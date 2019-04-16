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

namespace Espo\Modules\Pim\SelectManagers;

use Espo\Modules\Pim\Core\SelectManagers\AbstractSelectManager;
use PDO;

/**
 * Image select manager
 *
 * @author r.ratsun <r.ratsun@treolabs.com>
 */
class Image extends AbstractSelectManager
{

    /**
     * notLinkedWithProduct filter
     *
     * @param array $result
     */
    protected function boolFilterNotLinkedWithProduct(array &$result)
    {
        if (!empty($productId = (string)$this->getSelectCondition('notLinkedWithProduct'))) {
            foreach ($this->getProductImages($productId) as $row) {
                $result['whereClause'][] = [
                    'id!=' => $row['productImageId']
                ];
            }
        }
    }

    /**
     * notLinkerWithCategory filter
     *
     * @param array $result
     */
    protected function boolFilterNotLinkedWithCategory(array &$result)
    {
        if (!empty($categoryId = (string)$this->getSelectCondition('notLinkedWithProduct'))) {
            foreach ($this->getCategoryImages($categoryId) as $row) {
                $result['whereClause'][] = [
                    'id!=' => $row['categoryImageId']
                ];
            }
        }
    }

    /**
     * Get images related to product
     *
     * @param string $productId
     *
     * @return array
     */
    protected function getProductImages(string $productId): array
    {
        $sql
            = 'SELECT image_id AS productImageId
                FROM product_image_linker
                WHERE deleted = 0 AND product_id = :productId';

        $sth = $this->getEntityManager()->getPDO()->prepare($sql);
        $sth->execute(['productId' => $productId]);

        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get images related to category
     *
     * @param string $categoryId
     *
     * @return array
     */
    protected function getCategoryImages(string $categoryId): array
    {
        $sql = '
            SELECT image_id as categoryImageId
            FROM category_image_linker
            WHERE deleted = 0 AND category_id = :categoryId';

        $sth = $this->getEntityManager()->getPDO()->prepare($sql);
        $sth->execute(['productId' => $categoryId]);

        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }
}
