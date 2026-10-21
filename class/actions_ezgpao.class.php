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
 * \file    class/actions_ezgpao.class.php
 * \ingroup ezgpao
 * \brief   Hooks of EzGpao: kit to BOM on products, sales order to MO on orders.
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';

/**
 * Hooks of the EzGpao module
 */
class ActionsEzGpao extends CommonHookActions
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Tell whether a user may turn kits into BOMs
     *
     * Creating the BOM needs the BOM write right, removing the kit composition the product one.
     *
     * @param  User $user User to check
     * @return bool       True if allowed
     */
    private function canConvertKit(User $user): bool
    {
        return isModEnabled('bom') && $user->hasRight('bom', 'write') && $user->hasRight('produit', 'creer');
    }

    /**
     * Tell whether a user may create MOs from sales orders
     *
     * @param  User $user User to check
     * @return bool       True if allowed
     */
    private function canCreateMo(User $user): bool
    {
        return isModEnabled('mrp') && isModEnabled('order') && $user->hasRight('mrp', 'write') && $user->hasRight('commande', 'lire');
    }

    /**
     * Tell whether an object is a kit product that can be turned into a BOM
     *
     * @param  CommonObject $object Object shown by the page
     * @return bool                 True for a product (not a service) that has kit components
     */
    private function isConvertibleKit(CommonObject $object): bool
    {
        return $object instanceof Product && $object->id > 0 && (int) $object->type == Product::TYPE_PRODUCT && $object->hasFatherOrChild(1) > 0;
    }

    /**
     * Get the hook contexts of the current call
     *
     * @param  array<string, mixed> $parameters Hook parameters
     * @return string[]                         Contexts
     */
    private function getContexts(array $parameters): array
    {
        return explode(':', (string) ($parameters['context'] ?? ''));
    }

    /**
     * Turn a list of kit products into BOMs, one transaction per product, and report the result
     *
     * @param  int[] $productIds Ids of products
     * @param  User  $user       User doing the conversion
     * @return void
     */
    private function convertKits(array $productIds, User $user): void
    {
        global $langs;

        require_once __DIR__ . '/ezgpaokitconverter.class.php';

        $converter = new EzGpaoKitConverter($this->db);
        $converted = [];
        $skipped   = [];

        foreach ($productIds as $productId) {
            $product = new Product($this->db);
            if ($product->fetch((int) $productId) <= 0) {
                continue;
            }
            if (!$this->isConvertibleKit($product)) {
                $skipped[] = $product->ref;
                continue;
            }

            $bomId = $converter->convert($product, $user);
            if ($bomId > 0) {
                $bom = new BOM($this->db);
                $bom->fetch($bomId);
                $converted[] = $langs->trans('EzGpaoKitConverted', $product->ref, $bom->getNomUrl(1));
            } else {
                setEventMessages($langs->trans('EzGpaoErrorKitConversion', $product->ref) . ' ' . $converter->error, $converter->errors, 'errors');
            }
        }

        if (!empty($converted)) {
            setEventMessages('', $converted, 'mesgs');
        }
        if (!empty($skipped)) {
            setEventMessages($langs->trans('EzGpaoProductsNotKits', implode(', ', $skipped)), [], 'warnings');
        }
    }

    /**
     * Create the MOs of a list of sales orders and report the result
     *
     * @param  int[] $orderIds Ids of sales orders
     * @param  User  $user     User creating the MOs
     * @return void
     */
    private function createMos(array $orderIds, User $user): void
    {
        global $langs;

        require_once __DIR__ . '/ezgpaoordertomo.class.php';

        $service = new EzGpaoOrderToMo($this->db);

        foreach ($orderIds as $orderId) {
            $order = new Commande($this->db);
            if ($order->fetch((int) $orderId) <= 0) {
                continue;
            }

            $result = $service->createFromOrder($order, $user);
            if ($result < 0) {
                setEventMessages($service->error, $service->errors, 'errors');
                continue;
            }

            if ($result > 0) {
                $links = [];
                foreach ($service->createdMos as $mo) {
                    $links[] = $mo->getNomUrl(1);
                }
                setEventMessages($langs->trans('EzGpaoMosCreated', $result, $order->ref, implode(', ', $links)), [], 'mesgs');
            }
            if (!empty($service->linesAlreadyCovered)) {
                setEventMessages($langs->trans('EzGpaoLinesAlreadyCovered', $order->ref, implode(', ', $service->linesAlreadyCovered)), [], 'warnings');
            }
            if (!empty($service->linesWithoutBom)) {
                setEventMessages($langs->trans('EzGpaoLinesWithoutBom', $order->ref, implode(', ', $service->linesWithoutBom)), [], 'warnings');
            }
            if ($result == 0 && empty($service->linesAlreadyCovered) && empty($service->linesWithoutBom)) {
                setEventMessages($langs->trans('EzGpaoNothingToManufacture', $order->ref), [], 'warnings');
            }
        }
    }

    /**
     * Execute the confirmed actions of the cards and of the product list
     *
     * @param  array<string, mixed> $parameters Hook parameters
     * @param  CommonObject         $object     Object of the page
     * @param  string               $action     Current action
     * @return int                              0 to keep the standard actions
     */
    public function doActions($parameters, &$object, &$action): int
    {
        global $langs, $user;

        $contexts = $this->getContexts($parameters);
        $confirm  = GETPOST('confirm', 'alpha');

        if ($action == 'confirm_ezgpao_kittobom' && $confirm == 'yes' && $this->canConvertKit($user)) {
            $langs->load('ezgpao@ezgpao');

            if (in_array('productcard', $contexts) && $object->id > 0) {
                $this->convertKits([(int) $object->id], $user);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id));
                exit;
            }
            if (in_array('productservicelist', $contexts)) {
                $this->convertKits(array_map('intval', GETPOST('toselect', 'array:int')), $user);
                $action = 'list';
            }
        }

        if ($action == 'confirm_ezgpao_createmo' && $confirm == 'yes' && in_array('ordercard', $contexts) && $object->id > 0 && $this->canCreateMo($user)) {
            $langs->load('ezgpao@ezgpao');

            $this->createMos([(int) $object->id], $user);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id));
            exit;
        }

        return 0;
    }

    /**
     * Execute the mass actions that need no confirmation
     *
     * @param  array<string, mixed> $parameters Hook parameters
     * @param  ?CommonObject        $object     Object of the page
     * @param  string               $action     Current action
     * @return int                              0 to keep the standard actions
     */
    public function doMassActions($parameters, &$object, &$action): int
    {
        global $langs, $user;

        if (($parameters['massaction'] ?? '') == 'ezgpao_createmo' && in_array('orderlist', $this->getContexts($parameters)) && $this->canCreateMo($user)) {
            $langs->load('ezgpao@ezgpao');

            $toselect = is_array($parameters['toselect'] ?? null) ? $parameters['toselect'] : [];
            $this->createMos(array_map('intval', $toselect), $user);
        }

        return 0;
    }

    /**
     * Ask for confirmation before the destructive mass actions
     *
     * @param  array<string, mixed> $parameters Hook parameters
     * @param  ?CommonObject        $object     Object of the page
     * @param  string               $action     Current action
     * @return int                              0 to keep the standard actions
     */
    public function doPreMassActions($parameters, &$object, &$action): int
    {
        global $langs, $user;

        if (($parameters['massaction'] ?? '') == 'ezgpao_prekittobom' && in_array('productservicelist', $this->getContexts($parameters)) && $this->canConvertKit($user)) {
            $langs->load('ezgpao@ezgpao');

            $form     = new Form($this->db);
            $toselect = is_array($parameters['toselect'] ?? null) ? $parameters['toselect'] : [];

            $this->resprints = $form->formconfirm($_SERVER['PHP_SELF'], $langs->trans('EzGpaoConvertKitsToBom'), $langs->trans('EzGpaoConfirmConvertKitsToBom', count($toselect)), 'confirm_ezgpao_kittobom', [], '', 0, 200, 500, 1);
        }

        return 0;
    }

    /**
     * Add the EzGpao entries to the mass actions of the lists
     *
     * @param  array<string, mixed> $parameters Hook parameters
     * @return int                              0 to keep the standard mass actions
     */
    public function addMoreMassActions($parameters): int
    {
        global $langs, $user;

        $contexts = $this->getContexts($parameters);
        $options  = [];

        if (in_array('productservicelist', $contexts) && $this->canConvertKit($user)) {
            $options['ezgpao_prekittobom'] = ['bom', 'EzGpaoConvertKitsToBom'];
        }
        if (in_array('orderlist', $contexts) && $this->canCreateMo($user)) {
            $options['ezgpao_createmo'] = ['mrp', 'EzGpaoCreateMos'];
        }

        if (!empty($options)) {
            $langs->load('ezgpao@ezgpao');

            $this->resprints = '';
            foreach ($options as $code => $option) {
                $label = img_picto('', $option[0], 'class="pictofixedwidth"') . $langs->trans($option[1]);
                $this->resprints .= '<option value="' . $code . '" data-html="' . dol_escape_htmltag($label) . '">' . $langs->trans($option[1]) . '</option>';
            }
        }

        return 0;
    }

    /**
     * Show the confirmation dialogs of the card actions
     *
     * @param  array<string, mixed> $parameters Hook parameters
     * @param  CommonObject         $object     Object of the page
     * @param  string               $action     Current action
     * @return int                              0 to keep the standard dialogs
     */
    public function formConfirm($parameters, &$object, &$action): int
    {
        global $langs, $user;

        $contexts = $this->getContexts($parameters);

        if ($action == 'ezgpao_kittobom' && in_array('productcard', $contexts) && $this->canConvertKit($user) && $this->isConvertibleKit($object)) {
            $langs->load('ezgpao@ezgpao');

            $form            = new Form($this->db);
            $this->resprints = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id), $langs->trans('EzGpaoConvertKitToBom'), $langs->trans('EzGpaoConfirmConvertKitToBom', $object->ref), 'confirm_ezgpao_kittobom', [], 'yes', 1);
        }

        if ($action == 'ezgpao_createmo' && in_array('ordercard', $contexts) && $this->canCreateMo($user)) {
            $langs->load('ezgpao@ezgpao');

            $form            = new Form($this->db);
            $this->resprints = $form->formconfirm($_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id), $langs->trans('EzGpaoCreateMos'), $langs->trans('EzGpaoConfirmCreateMos', $object->ref), 'confirm_ezgpao_createmo', [], 'yes', 1);
        }

        return 0;
    }

    /**
     * Add the action buttons of the product and sales order cards
     *
     * @param  array<string, mixed> $parameters Hook parameters
     * @param  CommonObject         $object     Object of the page
     * @param  string               $action     Current action
     * @return int                              0 to keep the standard buttons
     */
    public function addMoreActionsButtons($parameters, &$object, &$action): int
    {
        global $langs, $user;

        $contexts = $this->getContexts($parameters);

        if (in_array('productcard', $contexts) && $this->isConvertibleKit($object)) {
            $langs->load('ezgpao@ezgpao');

            $url = $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id) . '&action=ezgpao_kittobom&token=' . newToken();
            print dolGetButtonAction($langs->trans('EzGpaoConvertKitToBomHelp'), $langs->trans('EzGpaoConvertKitToBom'), 'default', $url, '', $this->canConvertKit($user));
        }

        if (in_array('ordercard', $contexts) && $object instanceof Commande && isModEnabled('mrp')) {
            require_once __DIR__ . '/ezgpaoordertomo.class.php';

            if (EzGpaoOrderToMo::isOrderEligible($object)) {
                $langs->load('ezgpao@ezgpao');

                $url = $_SERVER['PHP_SELF'] . '?id=' . ((int) $object->id) . '&action=ezgpao_createmo&token=' . newToken();
                print dolGetButtonAction($langs->trans('EzGpaoCreateMosHelp'), $langs->trans('EzGpaoCreateMos'), 'default', $url, '', $this->canCreateMo($user));
            }
        }

        return 0;
    }
}
