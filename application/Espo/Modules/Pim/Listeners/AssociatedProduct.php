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

namespace Espo\Modules\Pim\Listeners;

use Treo\Listeners\AbstractListener;
use PDO;

/**
 * Class AssociatedProduct
 *
 * @author r.zablodskiy@treolabs.com
 */
class AssociatedProduct extends AbstractListener
{
    /**
     * After action list
     *
     * @param array $data
     *
     * @return array
     */
    public function afterActionList(array $data): array
    {
        $data['result']['list'] = $this->setAssociatedProductsImage((array)$data['result']['list']);

        return $data;
    }

    /**
     * After action read
     *
     * @param array $data
     *
     * @return array
     */
    public function afterActionRead(array $data): array
    {
        $data['result'] = $this->setAssociatedProductsImage((array)$data['result']);

        return $data;
    }

    /**
     * Set main images for associated products
     *
     * @param array $result
     *
     * @return \stdClass
     */
    protected function setAssociatedProductsImage(array $result): array
    {
        $productIds = [];
        foreach ($result as $item) {
            if (isset($item->{'mainProductId'}) && !in_array($item->{'mainProductId'}, $productIds)) {
                $productIds[] = $item->{'mainProductId'};
            }

            if (isset($item->{'relatedProductId'}) && !in_array($item->{'relatedProductId'}, $productIds)) {
                $productIds[] = $item->{'relatedProductId'};
            }
        }

        $images = $this->getDBAssociatedProductsMainImage($productIds);

        foreach ($result as $key => $item) {
            if ($images[$item->mainProductId]) {
                $result[$key]->{'mainProductImageId'} = !empty($images[$item->mainProductId]['imageId'])
                    ? $images[$item->mainProductId]['imageId'] : null;
                $result[$key]->{'mainProductImageLink'} = !empty($images[$item->mainProductId]['imageLink'])
                    ? $images[$item->mainProductId]['imageLink'] : null;
            }

            if ($images[$item->relatedProductId]) {
                $result[$key]->{'relatedProductImageId'} = !empty($images[$item->relatedProductId]['imageId'])
                    ? $images[$item->relatedProductId]['imageId'] : null;
                $result[$key]->{'relatedProductImageLink'} = !empty($images[$item->relatedProductId]['imageLink'])
                    ? $images[$item->relatedProductId]['imageLink'] : null;

            }
        }

        return $result;
    }

    /**
     * Get product main image
     *
     * @param array $productIds
     *
     * @return array
     */
    protected function getDBAssociatedProductsMainImage(array $productIds): array
    {
        $result = [];
        $productIds = "'" . implode("','", $productIds) . "'";
        if (!empty($productIds)) {
            $sql
                =  "SELECT
                       pil.product_id AS productId,
                       pi.id AS imageId,
                       pi.is_link AS isLink,
                       pi.image_link AS imageLink,
                       pil.sort_order
                    FROM image pi
                      JOIN product_image_linker pil
                        ON pil.image_id = pi.id AND pil.id = (
                          SELECT id
                          FROM product_image_linker
                          WHERE product_id = pil.product_id AND deleted = 0
                          ORDER BY sort_order, id
                          LIMIT 1
                        )
                    WHERE pil.product_id IN ({$productIds}) AND pi.deleted = 0";

            $sth = $this->getEntityManager()->getPDO()->prepare($sql);
            $sth->execute();

            $result = $sth->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE);

            return is_array($result) ? $result : [];
        }

        return $result;
    }
}
