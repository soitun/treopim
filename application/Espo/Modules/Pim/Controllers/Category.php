<?php
/**
 * Pim
 * Free Extension
 * Copyright (c) TreoLabs GmbH
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
declare(strict_types=1);

namespace Espo\Modules\Pim\Controllers;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;

/**
 * Class Category
 *
 * @author r.ratsun <r.ratsun@treolabs.com>
 */
class Category extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * @ApiDescription(description="Update sort order for category images")*
     * @ApiMethod(type="PUT")
     * @ApiRoute(name="CategoryImage/{id}/sortOrder")
     * @ApiParams(name="id", type="string", is_required=1, description="Id")
     * @ApiBody(sample="{'ids': 'array'}")
     * @ApiReturn(sample="'bool'")
     *
     * @param array  $params
     * @param array  $data
     * @param object $request
     *
     * @return bool
     * @throws BadRequest
     * @throws Forbidden
     */
    public function actionUpdateImageSortOrder($params, $data, $request): bool
    {
        // is put?
        if (!$request->isPut()) {
            throw new BadRequest();
        }

        // is granted?
        if (!$this->getAcl()->check($this->name, 'edit')) {
            throw new Forbidden();
        }

        return $this->getRecordService()->updateImageSortOrder($params['id'], $data->ids);
    }
}
