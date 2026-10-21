<?php
/* Copyright (C) 2026 EVARISK <technique@evarisk.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    class/ezgpaoordertomo.class.php
 * \ingroup ezgpao
 * \brief   Creates the manufacturing orders (MO) needed by a sales order.
 */

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/mrp/class/mo.class.php';

/**
 * Sales order to manufacturing orders.
 *
 * One draft MO per order line whose product has a validated manufacturing BOM, for the quantity
 * of the line. Stock is not checked (planned for a later version): the whole line is produced.
 */
class EzGpaoOrderToMo
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Last error message
     */
    public $error = '';

    /**
     * @var string[] Error messages
     */
    public $errors = [];

    /**
     * @var Mo[] Manufacturing orders created by the last call to createFromOrder()
     */
    public $createdMos = [];

    /**
     * @var string[] Refs of the products skipped because they have no validated BOM
     */
    public $linesWithoutBom = [];

    /**
     * @var string[] Refs of the products skipped because a MO is already linked to the order for them
     */
    public $linesAlreadyCovered = [];

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * Tell whether MOs can be created from an order in its current status
     *
     * @param  Commande $order Sales order
     * @return bool            True if the order is validated or partially shipped
     */
    public static function isOrderEligible(Commande $order): bool
    {
        return in_array((int) $order->status, [Commande::STATUS_VALIDATED, Commande::STATUS_SHIPMENTONPROCESS], true);
    }

    /**
     * Get the BOM to use to manufacture a product
     *
     * The default BOM of the product wins when it is a validated manufacturing BOM, otherwise the
     * most recent validated manufacturing BOM of the product is used.
     *
     * @param  int $productId Id of product
     * @return int            Id of BOM, 0 if none, -1 if KO
     */
    public function getBomIdForProduct(int $productId): int
    {
        $sql  = 'SELECT b.rowid, (CASE WHEN b.rowid = p.fk_default_bom THEN 1 ELSE 0 END) as isdefault';
        $sql .= ' FROM ' . $this->db->prefix() . 'bom_bom as b';
        $sql .= ' INNER JOIN ' . $this->db->prefix() . 'product as p ON p.rowid = b.fk_product';
        $sql .= ' WHERE b.fk_product = ' . ((int) $productId);
        $sql .= ' AND b.status = ' . ((int) BOM::STATUS_VALIDATED);
        $sql .= ' AND b.bomtype = 0';
        $sql .= ' AND b.entity IN (' . getEntity('bom') . ')';
        $sql .= ' ORDER BY isdefault DESC, b.rowid DESC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj ? (int) $obj->rowid : 0;
    }

    /**
     * Count, per product, the MOs already linked to an order and not canceled
     *
     * Links are read in both directions: a user may have linked the order from the MO card.
     *
     * @param  int             $orderId Id of sales order
     * @return array<int, int>|int      Number of MOs indexed by product id, or -1 if KO
     */
    public function countLinkedMosByProduct(int $orderId)
    {
        $sql  = 'SELECT m.fk_product, COUNT(DISTINCT m.rowid) as nb';
        $sql .= ' FROM ' . $this->db->prefix() . 'mrp_mo as m';
        $sql .= ' INNER JOIN ' . $this->db->prefix() . 'element_element as ee ON (';
        $sql .= "(ee.sourcetype = 'commande' AND ee.fk_source = " . ((int) $orderId) . " AND ee.targettype = 'mo' AND ee.fk_target = m.rowid)";
        $sql .= " OR (ee.sourcetype = 'mo' AND ee.fk_source = m.rowid AND ee.targettype = 'commande' AND ee.fk_target = " . ((int) $orderId) . ')';
        $sql .= ')';
        $sql .= ' WHERE m.status <> ' . ((int) Mo::STATUS_CANCELED);
        $sql .= ' GROUP BY m.fk_product';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $counts = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $counts[(int) $obj->fk_product] = (int) $obj->nb;
        }
        $this->db->free($resql);

        return $counts;
    }

    /**
     * Create one draft MO per order line that has a validated BOM
     *
     * Lines already covered by a MO linked to the order are skipped, so that running the action
     * twice does not duplicate the MOs. All MOs of the order are created in one transaction.
     *
     * @param  Commande $order Sales order (fetched, with its lines)
     * @param  User     $user  User creating the MOs
     * @return int             Number of MOs created (>= 0) if OK, <0 if KO
     */
    public function createFromOrder(Commande $order, User $user): int
    {
        global $langs;

        $this->error               = '';
        $this->errors              = [];
        $this->createdMos          = [];
        $this->linesWithoutBom     = [];
        $this->linesAlreadyCovered = [];

        if (!self::isOrderEligible($order)) {
            $this->error = $langs->trans('EzGpaoErrorOrderNotEligible', $order->ref);
            return -2;
        }

        $alreadyLinked = $this->countLinkedMosByProduct((int) $order->id);
        if (!is_array($alreadyLinked)) {
            return -1;
        }

        if (empty($order->lines)) {
            $order->fetch_lines();
        }

        $this->db->begin();

        foreach ($order->lines as $line) {
            if (empty($line->fk_product) || (int) $line->product_type != Product::TYPE_PRODUCT || $line->qty <= 0) {
                continue;
            }
            $productId = (int) $line->fk_product;

            if (!empty($alreadyLinked[$productId])) {
                $alreadyLinked[$productId]--;
                $this->linesAlreadyCovered[] = $line->product_ref;
                continue;
            }

            $bomId = $this->getBomIdForProduct($productId);
            if ($bomId < 0) {
                $this->db->rollback();
                return -1;
            }
            if ($bomId == 0) {
                $this->linesWithoutBom[] = $line->product_ref;
                continue;
            }

            $bom = new BOM($this->db);
            $bom->fetch($bomId);

            $mo             = new Mo($this->db);
            $mo->fk_bom     = $bomId;
            $mo->fk_product = $productId;
            $mo->qty        = (float) $line->qty;
            $mo->label      = $langs->transnoentitiesnoconv('EzGpaoMoLabel', $order->ref, $line->product_ref);
            $mo->fk_soc     = (int) $order->socid;
            $mo->status     = Mo::STATUS_DRAFT;
            if ($order->fk_project > 0) {
                $mo->fk_project = (int) $order->fk_project;
            }
            if ($bom->fk_warehouse > 0) {
                $mo->fk_warehouse = (int) $bom->fk_warehouse;
            }
            if (!empty($order->delivery_date)) {
                $mo->date_end_planned = $order->delivery_date;
            }

            if ($mo->create($user) <= 0) {
                $this->error  = $langs->trans('EzGpaoErrorMoCreation', $line->product_ref) . ' ' . $langs->trans($mo->error);
                $this->errors = $mo->errors;
                $this->db->rollback();
                return -1;
            }

            if ($order->add_object_linked('mo', $mo->id) <= 0) {
                $this->error  = $order->error;
                $this->errors = $order->errors;
                $this->db->rollback();
                return -1;
            }

            $this->createdMos[] = $mo;
        }

        $this->db->commit();

        return count($this->createdMos);
    }
}
