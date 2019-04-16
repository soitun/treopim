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

namespace Espo\Modules\Pim\Hooks\Image;

use Espo\Modules\Pim\Core\Hooks\AbstractHook;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;

/**
 * Class Image
 *
 * @author r.zablodskiy@treolabs.com
 */
class ProductImage extends AbstractHook
{
    /**
     * @param Entity $entity
     * @param array $options
     *
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, $options = [])
    {
        // is code valid
        if (!$this->isCodeValid($entity, 'name')) {
            throw new BadRequest($this->translate('Code is invalid', 'exceptions'));
        }
    }
}
