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
 * \file    class/ezgpaokitconverter.class.php
 * \ingroup ezgpao
 * \brief   Turns a kit (virtual product) into an autonomous, validated BOM.
 */

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';

/**
 * Kit to BOM converter.
 *
 * The BOM is a one-shot copy of the kit composition: once converted, the kit links are removed
 * from the product and nothing keeps the two in sync. Removing the kit is not optional: Dolibarr
 * refuses to create a manufacturing order on a product that is still a kit
 * (ErrorAVirtualProductCantBeUsedIntoABomOrMo in Mo::create()).
 */
class EzGpaoKitConverter
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var string Last error message (translation key or text)
     */
    public $error = '';

    /**
     * @var string[] Error messages
     */
    public $errors = [];

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
     * Get the first level components of a kit, in the order of the kit
     *
     * @param  int                                                    $productId Id of the kit product
     * @return array<int, array{fk_product: int, qty: float, incdec: int}>|int    Components, or -1 if KO
     */
    public function getKitComponents(int $productId)
    {
        $sql  = 'SELECT pa.fk_product_fils, pa.qty, pa.incdec';
        $sql .= ' FROM ' . $this->db->prefix() . 'product_association as pa';
        $sql .= ' WHERE pa.fk_product_pere = ' . ((int) $productId);
        $sql .= ' ORDER BY pa.rang ASC, pa.rowid ASC';

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $components = [];
        while ($obj = $this->db->fetch_object($resql)) {
            $components[] = [
                'fk_product' => (int) $obj->fk_product_fils,
                'qty'        => (float) $obj->qty,
                'incdec'     => (int) $obj->incdec,
            ];
        }
        $this->db->free($resql);

        return $components;
    }

    /**
     * Get the validated manufacturing BOM of a product, if any
     *
     * @param  int $productId Id of product
     * @return int            Id of the most recent validated BOM, 0 if none, -1 if KO
     */
    public function getValidatedBomId(int $productId): int
    {
        $sql  = 'SELECT b.rowid FROM ' . $this->db->prefix() . 'bom_bom as b';
        $sql .= ' WHERE b.fk_product = ' . ((int) $productId);
        $sql .= ' AND b.status = ' . ((int) BOM::STATUS_VALIDATED);
        $sql .= ' AND b.bomtype = 0';
        $sql .= ' AND b.entity IN (' . getEntity('bom') . ')';
        $sql .= ' ORDER BY b.rowid DESC';

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
     * Convert a kit into a validated BOM, then remove the kit composition from the product
     *
     * Everything happens in one transaction: if anything fails, the kit is left untouched.
     *
     * @param  Product $product Kit product (fetched)
     * @param  User    $user    User doing the conversion
     * @return int              Id of the created BOM if OK, <0 if KO
     */
    public function convert(Product $product, User $user): int
    {
        global $langs;

        $this->error  = '';
        $this->errors = [];

        $components = $this->getKitComponents((int) $product->id);
        if (!is_array($components)) {
            return -1;
        }
        if (empty($components)) {
            $this->error = $langs->trans('EzGpaoErrorNotAKit', $product->ref);
            return -2;
        }

        $existingBomId = $this->getValidatedBomId((int) $product->id);
        if ($existingBomId < 0) {
            return -1;
        }
        if ($existingBomId > 0) {
            $existingBom = new BOM($this->db);
            $existingBom->fetch($existingBomId);
            $this->error = $langs->trans('EzGpaoErrorProductAlreadyHasBom', $product->ref, $existingBom->ref);
            return -3;
        }

        $this->db->begin();

        $bom              = new BOM($this->db);
        $bom->label       = $product->label;
        $bom->fk_product  = (int) $product->id;
        $bom->qty         = 1;
        $bom->bomtype     = 0;
        $bom->efficiency  = 1;
        $bom->status      = BOM::STATUS_DRAFT;
        $bom->description = $langs->transnoentitiesnoconv('EzGpaoBomCreatedFromKit', $product->ref);
        if ($product->fk_default_warehouse > 0) {
            $bom->fk_warehouse = (int) $product->fk_default_warehouse;
        }

        $bomId = $bom->create($user);
        if ($bomId <= 0) {
            return $this->rollbackWith($bom);
        }

        foreach ($components as $component) {
            $componentProduct = new Product($this->db);
            if ($componentProduct->fetch($component['fk_product']) <= 0) {
                $this->error = $langs->trans('EzGpaoErrorComponentNotFound', $component['fk_product']);
                $this->db->rollback();
                return -1;
            }

            // A kit component flagged "no stock change" stays so in the BOM
            $disableStockChange = empty($component['incdec']) ? 1 : 0;
            $result = $bom->addLine($component['fk_product'], $component['qty'], 0, $disableStockChange, 1.0, -1, null, null, (int) $componentProduct->fk_unit);
            if ($result <= 0) {
                return $this->rollbackWith($bom);
            }
        }

        if ($bom->validate($user) <= 0) {
            return $this->rollbackWith($bom);
        }

        foreach ($components as $component) {
            if ($product->del_sousproduit((int) $product->id, $component['fk_product']) < 0) {
                return $this->rollbackWith($product);
            }
        }

        // The new BOM becomes the default one, unless the product already points to a BOM
        if (empty($product->fk_default_bom)) {
            $sql  = 'UPDATE ' . $this->db->prefix() . 'product SET fk_default_bom = ' . ((int) $bomId);
            $sql .= ' WHERE rowid = ' . ((int) $product->id);
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                $this->db->rollback();
                return -1;
            }
            $product->fk_default_bom = $bomId;
        }

        $this->db->commit();

        return $bomId;
    }

    /**
     * Roll back the current transaction and keep the errors of the failing object
     *
     * @param  CommonObject $object Object whose call failed
     * @return int                  -1
     */
    private function rollbackWith(CommonObject $object): int
    {
        global $langs;

        // Core objects often store a translation key as error
        $this->error  = $langs->trans((string) $object->error);
        $this->errors = $object->errors;
        $this->db->rollback();

        return -1;
    }
}
