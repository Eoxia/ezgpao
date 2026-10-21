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
 * \defgroup ezgpao     Module EzGpao
 * \brief    Simplified production management: kits to BOMs, sales orders to manufacturing orders.
 *
 * \file     core/modules/modEzGpao.class.php
 * \ingroup  ezgpao
 * \brief    Description and activation file for module EzGpao
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module EzGpao
 */
class modEzGpao extends DolibarrModules
{
    /**
     * Constructor. Define names, constants, directories, boxes, permissions
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;

        $this->numero          = 436370;
        $this->rights_class    = 'ezgpao';
        $this->family          = 'products';
        $this->module_position = '90';
        $this->name            = preg_replace('/^mod/i', '', get_class($this));
        $this->description     = 'EzGpaoDescription';
        $this->descriptionlong = 'EzGpaoDescriptionLong';
        $this->editor_name     = 'Evarisk';
        $this->editor_url      = 'https://evarisk.com';
        $this->version         = '23.0.0';
        $this->const_name      = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto           = 'mrp';

        $this->module_parts = [
            'hooks' => [
                'productcard',
                'productservicelist',
                'ordercard',
                'orderlist',
            ],
        ];

        $this->dirs            = [];
        $this->config_page_url = [];

        // Kits, BOMs and MOs are all core features: the module only adds the bridges between them.
        // Sales orders stay optional, the order bridge simply does not show without them.
        $this->depends      = ['modProduct', 'modBom', 'modMrp'];
        $this->requiredby   = [];
        $this->conflictwith = [];

        $this->langfiles             = ['ezgpao@ezgpao'];
        $this->phpmin                = [7, 4];
        $this->need_dolibarr_version = [23, 0];

        $this->const  = [];
        $this->tabs   = [];
        $this->boxes  = [];
        $this->rights = [];
        $this->menu   = [];
    }

    /**
     * Function called when module is enabled
     *
     * @param  string $options Options when enabling module ('', 'noboxes')
     * @return int             1 if OK, 0 if KO
     */
    public function init($options = ''): int
    {
        return $this->_init([], $options);
    }

    /**
     * Function called when module is disabled
     *
     * @param  string $options Options when disabling module ('', 'noboxes')
     * @return int             1 if OK, 0 if KO
     */
    public function remove($options = ''): int
    {
        return $this->_remove([], $options);
    }
}
