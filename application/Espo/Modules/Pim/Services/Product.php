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

namespace Espo\Modules\Pim\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Json;
use Espo\ORM\Entity;
use Espo\Orm\EntityManager;
use Espo\Core\Utils\Util;
use Slim\Http\Request;
use \PDO;
use Treo\Services\MassActions;

/**
 * Service of Product
 *
 * @author r.ratsun <r.ratsun@treolabs.com>
 */
class Product extends AbstractService
{

    /**
     * @var array
     */
    protected $linkSelectParams
        = [
            'images' => [
                'order'             => 'ASC',
                'orderBy'           => 'product_image_linker.sort_order',
                'additionalColumns' => [
                    'sortOrder' => 'sortOrder',
                    'scope'     => 'scope'
                ]
            ]
        ];

    /**
     * @var array
     */
    protected $duplicatingLinkList
        = [
            'categories',
            'attributes',
            'channelProductAttributeValues',
            'images',
            'bundleProducts',
            'associatedMainProducts',
            'productTypePackages'
        ];

    /**
     * Get accounts categories ids
     *
     * @param EntityManager $entityManager
     * @param array         $accountIds
     *
     * @return array
     */
    public static function getAccountCategoryIds(EntityManager $entityManager, array $accountIds): array
    {
        // prepare ids
        $ids = implode("','", $accountIds);

        $sql
            = "SELECT
                 category.id   AS categoryId
               FROM
                 account
               JOIN 
                 channel ON account.channel_id=channel.id AND channel.deleted=0
               JOIN 
                 catalog ON channel.catalog_id=catalog.id AND catalog.deleted=0
               JOIN 
                 category ON catalog.category_id=category.id AND catalog.deleted=0
               WHERE
                 account.deleted=0
                AND
                 account.id IN ('{$ids}')";

        $sth = $entityManager->getPDO()->prepare($sql);
        $sth->execute();

        $data = $sth->fetchAll(\PDO::FETCH_ASSOC);

        return (!empty($data)) ? array_column($data, 'categoryId') : [];
    }

    /**
     * Get accounts products ids
     *
     * @param EntityManager $entityManager
     * @param array         $accountIds
     *
     * @return array
     */
    public static function getAccountProductIds(EntityManager $entityManager, array $accountIds): array
    {
        // prepare result
        $result = [];

        if (!empty($categoryIds = self::getAccountCategoryIds($entityManager, $accountIds))) {
            // prepare sql variables
            $categoryIn = "'" . implode("','", $categoryIds) . "'";
            $categoryLike = '';
            foreach ($categoryIds as $id) {
                $categoryLike .= "OR category_route LIKE '%|{$id}|%'";
            }

            $sql
                = "SELECT
                  product.id    AS productId
                FROM 
                  product_category_linker
                JOIN 
                  product ON product.id=product_category_linker.product_id AND product.deleted=0
                JOIN 
                  category ON category.id=product_category_linker.category_id AND category.deleted=0
                WHERE
                  product_category_linker.deleted = 0
                 AND
                  product_category_linker.category_id 
                   IN (SELECT id FROM category WHERE deleted=0 AND (id IN ({$categoryIn}) {$categoryLike}))";

            $sth = $entityManager->getPDO()->prepare($sql);
            $sth->execute();

            $data = $sth->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($data)) {
                $result = array_column($data, 'productId');
            }
        }

        return $result;
    }

    /**
     * @param \stdClass $data
     *
     * @return bool
     */
    public function addAssociateProducts(\stdClass $data): bool
    {
        if (empty($data->ids)
            || empty($data->foreignIds)
            || empty($data->associationId)
            || !is_array($data->ids)
            || !is_array($data->foreignIds)
            || empty($association = $this->getEntityManager()->getEntity("Association", $data->associationId))) {
            return false;
        }

        // prepare repository
        $repository = $this->getEntityManager()->getRepository("AssociatedProduct");

        // find exists entities
        $entities = $repository->where(
            [
                'associationId'    => $data->associationId,
                'mainProductId'    => $data->ids,
                'relatedProductId' => $data->foreignIds
            ]
        )->find();

        // prepare exists
        $exists = [];
        if (!empty($entities)) {
            foreach ($entities as $entity) {
                $exists[] = $entity->get("associationId") . "_" . $entity->get("mainProductId") .
                    "_" . $entity->get("relatedProductId");
            }
        }

        foreach ($data->ids as $mainProductId) {
            foreach ($data->foreignIds as $relatedProductId) {
                if (!in_array($data->associationId . "_{$mainProductId}_{$relatedProductId}", $exists)) {
                    $entity = $repository->get();
                    $entity->set("associationId", $data->associationId);
                    $entity->set("mainProductId", $mainProductId);
                    $entity->set("relatedProductId", $relatedProductId);

                    // for backward association
                    if (!empty($backwardAssociationId = $association->get('backwardAssociationId'))) {
                        $entity->set('backwardAssociationId', $backwardAssociationId);

                        $backwardEntity = $repository->get();
                        $backwardEntity->set("associationId", $backwardAssociationId);
                        $backwardEntity->set("mainProductId", $relatedProductId);
                        $backwardEntity->set("relatedProductId", $mainProductId);

                        $this->getEntityManager()->saveEntity($backwardEntity);
                    }

                    $this->getEntityManager()->saveEntity($entity);
                }
            }
        }

        return true;
    }

    /**
     * Remove product association
     *
     * @param \stdClass $data
     *
     * @return bool
     */
    public function removeAssociateProducts(\stdClass $data): bool
    {
        if (empty($data->ids) || empty($data->foreignIds) || empty($data->associationId)) {
            return false;
        }

        // find associated products
        $associatedProducts = $this
            ->getEntityManager()
            ->getRepository('AssociatedProduct')
            ->where([
                'associationId'    => $data->associationId,
                'mainProductId'    => $data->ids,
                'relatedProductId' => $data->foreignIds
            ])
            ->find();

        if (count($associatedProducts) > 0) {
            foreach ($associatedProducts as $associatedProduct) {
                // for backward association
                if (!empty($backwardAssociationId = $associatedProduct->get('backwardAssociationId'))) {
                    $backwards = $associatedProduct->get('backwardAssociation')->get('associatedProducts');

                    if (count($backwards) > 0) {
                        foreach ($backwards as $backward) {
                            if ($backward->get('mainProductId') == $associatedProduct->get('relatedProductId')
                                && $backward->get('relatedProductId') == $associatedProduct->get('mainProductId')
                                && $backward->get('associationId') == $backwardAssociationId) {
                                $this->getEntityManager()->removeEntity($backward);
                            }
                        }
                    }
                }

                // remove associated product
                $this->getEntityManager()->removeEntity($associatedProduct);
            }
        }

        return true;
    }

    /**
     * Set duplicating links
     *
     * @param array $links
     */
    public function setDuplicatingLinkList(array $links)
    {
        $this->duplicatingLinkList = array_merge($this->duplicatingLinkList, $links);
    }

    /**
     * Get item in products data
     *
     * @param string  $productId
     * @param Request $request
     *
     * @return array
     */
    public function getItemInProducts(string $productId, Request $request): array
    {
        // prepare result
        $result = [
            'total' => 0,
            'list'  => []
        ];

        // get total
        $total = $this->getDbCountItemInProducts($productId);

        if (!empty($total)) {
            // prepare result
            $result = [
                'total' => $total,
                'list'  => $this->getDbItemInProducts($productId, $request)
            ];
        }

        return $result;
    }

    /**
     * Get Product Attributes
     *
     * @param string $productId
     *
     * @return array
     */
    public function getAttributes(string $productId): array
    {
        // check ACL
        if (!$this->getAcl()->check('ProductAttributeValue', 'read')) {
            // prepare message
            $message = $this->getTranslate("You have no ACL rights to read attribute values", 'exceptions', 'ProductAttributeValue');

            throw new Forbidden($message);
        }

        // get product
        if (empty($product = $this->getEntityManager()->getEntity('Product', $productId))) {
            throw new NotFound();
        }

        // prepare result
        $result = [];

        if (!empty($attributeValues = $product->getProductAttributes()) && count($attributeValues) > 0) {
            // get config data
            $isMultilangActive = $this->getConfig()->get('isMultilangActive');
            $inputLanguageList = $this->getConfig()->get('inputLanguageList');
            $multilangFields = $this->getConfig()->get('modules')['multilangFields'];

            foreach ($attributeValues as $attributeValue) {
                // prepare data
                $attribute = $attributeValue->get('attribute');
                $productFamily = $attributeValue->get('productFamily');
                $attributeGroup = $attribute->get('attributeGroup');
                $isRequired = false;
                if (!empty($productFamily)) {
                    $isRequired = $productFamily->isAttributeRequired($attributeValue->get('attributeId'));
                }

                // prepare teams data
                $teamsData = [];
                if (!empty($teams = $attributeValue->get('teams'))) {
                    $teamsData = $teams->toArray();
                }

                // prepare item
                $item = [
                    'productAttributeValueId' => $attributeValue->get('id'),
                    'attributeId'             => $attributeValue->get('attributeId'),
                    'name'                    => $attributeValue->get('attributeName'),
                    'type'                    => $attribute->get('type'),
                    'isRequired'              => $isRequired,
                    'editable'                => $this->getAcl()->check($attributeValue, 'edit'),
                    'deletable'               => $this->getAcl()->check($attributeValue, 'delete'),
                    'attributeGroupId'        => $attribute->get('attributeGroupId'),
                    'attributeGroupName'      => $attribute->get('attributeGroupName'),
                    'attributeGroupOrder'     => (!empty($attributeGroup)) ? $attributeGroup->get('sortOrder') : 0,
                    'isCustom'                => empty($attributeValue->get('productFamilyId')),
                    'value'                   => $attributeValue->get('value'),
                    'typeValue'               => $attribute->get('typeValue'),
                    'sortOrder'               => $attribute->get('sortOrder'),
                    'ownerUserId'             => $attributeValue->get('ownerUserId'),
                    'ownerUserName'           => $attributeValue->get('ownerUserName'),
                    'assignedUserId'          => $attributeValue->get('assignedUserId'),
                    'assignedUserName'        => $attributeValue->get('assignedUserName'),
                    'teamsIds'                => array_column($teamsData, 'id'),
                    'teamsNames'              => array_column($teamsData, 'name', 'id'),
                    'data'                    => $attributeValue->get('data')
                ];

                // for multilang
                if ($isMultilangActive) {
                    foreach ($inputLanguageList as $locale) {
                        // prepare locale
                        $locale = Util::toCamelCase(strtolower($locale), '_', true);

                        // push
                        $item["name{$locale}"] = $attribute->get("name{$locale}");
                        $item["value{$locale}"] = $attributeValue->get("value{$locale}");
                        $item["typeValue{$locale}"] = $attribute->get("typeValue{$locale}");
                    }
                } elseif (!empty($multilangFields[$item['type']])) {
                    $item['type'] = $multilangFields[$item['type']]['fieldType'];
                }

                // push
                $result[] = $item;
            }
        }

        return $this->formatAttributeData($result);
    }

    /**
     * Get Channel product attributes
     *
     * @param string $productId
     *
     * @return array
     * @throws BadRequest
     * @throws Forbidden
     */
    public function getChannelAttributes(string $productId): array
    {
        // check ACL
        if (!$this->getAcl()->check('ChannelProductAttributeValue', 'read')) {
            throw new Forbidden();
        }

        // get product
        $product = $this->getEntityManager()->getEntity('Product', $productId);

        if (empty($product)) {
            throw new NotFound("No such product");
        }

        // prepare result
        $result = [];

        // push channels
        if (!empty($channels = $product->getChannels())) {
            foreach ($channels as $channel) {
                $result[$channel->get('id')]['channelId'] = $channel->get('id');
                $result[$channel->get('id')]['channelName'] = $channel->get('name');
                $result[$channel->get('id')]['locales'] = $channel->get('locales');
                $result[$channel->get('id')]['attributes'] = [];
            }
        }

        // push attributes
        if (!empty($data = $product->getProductChannelAttributes())) {
            foreach ($data as $k => $item) {
                // prepare data
                $teamsData = [];
                if (!empty($teams = $item->get('teams'))) {
                    $teamsData = $teams->toArray();
                }
                $productAttribute = $item->get('productAttribute');
                $attribute = $productAttribute->get('attribute');
                $attributeGroup = $attribute->get('attributeGroup');
                $attributeGroupOrder = (!empty($attributeGroup)) ? $attributeGroup->get('sortOrder') : null;
                $attributeValue = $this->prepareValue($attribute->get('type'), (string)$item->get('value'));
                $attributeIsRequired = false;
                $attributeIsMultiChannel = false;
                if (!empty($productFamily = $product->get('productFamily'))) {
                    $attributeIsRequired = $productFamily->isAttributeRequired($productAttribute->get('attributeId'));
                    $attributeIsMultiChannel = $productFamily->isAttributeMultiChannel($productAttribute->get('attributeId'));
                }

                // push
                $result[$item->get('channelId')]['attributes'][$k] = [
                    'channelProductAttributeValueId' => $item->get('id'),
                    'productId'                      => $productAttribute->get('productId'),
                    'attributeId'                    => $productAttribute->get('attributeId'),
                    'attributeName'                  => $productAttribute->get('attributeName'),
                    'attributeType'                  => $attribute->get('type'),
                    'attributeData'                  => $item->get('data'),
                    'attributeIsRequired'            => $attributeIsRequired,
                    'attributeIsMultiChannel'        => $attributeIsMultiChannel,
                    'attributeGroupId'               => $attribute->get('attributeGroupId'),
                    'attributeGroupName'             => $attribute->get('attributeGroupName'),
                    'attributeGroupOrder'            => $attributeGroupOrder,
                    'editable'                       => $this->getAcl()->check($item, 'edit'),
                    'deletable'                      => $this->getAcl()->check($item, 'delete'),
                    'ownerUserId'                    => $item->get('ownerUserId'),
                    'ownerUserName'                  => $item->get('ownerUserName'),
                    'assignedUserId'                 => $item->get('assignedUserId'),
                    'assignedUserName'               => $item->get('assignedUserName'),
                    'teamsIds'                       => array_column($teamsData, 'id'),
                    'teamsNames'                     => array_column($teamsData, 'name', 'id'),
                    'attributeTypeValue'             => $attribute->get('typeValue'),
                    'attributeValue'                 => $attributeValue
                ];

                // for multilang
                if (!empty($languages = $this->getConfig()->get('inputLanguageList'))) {
                    foreach ($languages as $language) {
                        // prepare language
                        $lang = Util::toCamelCase(strtolower($language), '_', true);

                        // get multilang data
                        $typeValue = $attribute->get('typeValue' . $lang);
                        $value = $this->prepareValue($attribute->get('type'), (string)$item->get('value' . $lang));

                        // push
                        $result[$item->get('channelId')]['attributes'][$k]['attributeTypeValue' . $lang] = $typeValue;
                        $result[$item->get('channelId')]['attributes'][$k]['attributeValue' . $lang] = $value;
                    }
                }
            }

            // prepare attributes
            foreach ($result as $channelId => $rows) {
                $result[$channelId]['attributes'] = array_values($rows['attributes']);
            }
        }

        return array_values($result);
    }

    /**
     * Update attribute value
     *
     * @param string $productId
     * @param array  $data
     *
     * @return bool
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function updateAttributes(string $productId, array $data)
    {
        // check ACL
        if (!$this->getAcl()->check('ProductAttributeValue', 'edit')) {
            throw new Forbidden();
        }

        // find product
        if (empty($product = $this->getEntityManager()->getEntity('Product', $productId))) {
            throw new NotFound();
        }

        foreach ($data as $row) {
            if (empty($row->attributeId)) {
                throw new BadRequest('Wrong attribute id');
            }

            foreach ($row as $field => $value) {
                if (strpos($field, 'value') !== false) {
                    // prepare key
                    $key = "attr_" . $row->attributeId . str_replace("value", "", $field);

                    // set
                    $product->set($key, $value);
                } elseif ($field != 'attributeId') {
                    if (is_array($value) || is_object($value)) {
                        $value = Json::encode($value);
                    }

                    $product->setProductAttributeData($row->attributeId, $field, $value);
                }
            }

            // trigger event
            if (!empty($attributeValue = $product->getProductAttribute($row->attributeId))) {
                $this->triggered('Product', 'updateAttribute', [
                    'attributeValue' => $attributeValue,
                    'post' => Json::decode(Json::encode($row), true),
                    'productId' => $productId
                ]);
            }
        }

        $this->getEntityManager()->saveEntity($product);

        return true;
    }

    /**
     * Get Channels for product
     *
     * @param string $productId
     *
     * @return array
     */
    public function getChannels(string $productId): array
    {
        return $this->prepareGetChannels($this->getDBChannel($productId));
    }

    /**
     * Get ids all active categories in tree
     *
     * @param string $productId
     *
     * @return array
     */
    public function getCategories(string $productId): array
    {
        // get categories
        $data = $this
            ->getEntityManager()
            ->getRepository('Product')
            ->getCategoriesArray([$productId], true);

        return !empty($data) ? array_column($data, 'categoryId') : [];
    }

    /**
     * Return multiLang fields name in DB and alias
     *
     * @param string $fieldName
     *
     * @return array
     */
    public function getMultiLangName(string $fieldName): array
    {
        // all fields
        $valueMultiLang = [];
        // prepare field name
        if (preg_match_all('/[^_]+/', $fieldName, $fieldParts, PREG_PATTERN_ORDER) > 1) {
            foreach ($fieldParts[0] as $key => $value) {
                $fieldAlias[] = $key > 0 ? ucfirst($value) : $value;
            }
            $fieldAlias = implode($fieldAlias);
        } else {
            $fieldAlias = $fieldName;
        }

        $fields['db_field'] = $fieldName;
        $fields['alias'] = $fieldAlias;
        $valueMultiLang[] = $fields;
        if ($this->getConfig()->get('isMultilangActive')) {
            $languages = $this->getConfig()->get('inputLanguageList');
            foreach ($languages as $language) {
                $language = strtolower($language);
                $fields['db_field'] = $fieldName . '_' . $language;

                $alias = preg_split('/_/', $language);
                $alias = array_map('ucfirst', $alias);
                $alias = implode($alias);
                $fields['alias'] = $fieldAlias . $alias;
                $valueMultiLang[] = $fields;
                unset($fields);
            }
        }

        return $valueMultiLang;
    }

    /**
     * Update image sort order
     *
     * @param string $id
     * @param array $imagesIds
     *
     * @return bool
     */
    public function updateImageSortOrder(string $id, array $imagesIds): bool
    {
        $result = false;

        if (!empty($imagesIds)) {
            $sql = '';
            foreach ($imagesIds as $k => $imageId) {
                $sql .= "UPDATE product_image_linker SET sort_order=$k WHERE image_id='$imageId' AND product_id='$id';";
            }

            // update DB data
            $sth = $this->getEntityManager()->getPDO()->prepare($sql);
            $sth->execute();

            // prepare result
            $result = true;
        }

        return $result;
    }

    /**
     * Get channels from DB
     *
     * @param string $productId
     *
     * @param bool   $onlyActive
     *
     * @return array
     * @throws Error
     */
    protected function getDBChannel(string $productId, bool $onlyActive = false): array
    {
        // prepare result
        $result = [];

        if (!empty($categories = $this->getCategories($productId))) {
            // prepare where
            $where = '';
            if ($onlyActive) {
                $where .= ' AND channel.is_active = 1';
                $where .= ' AND catalog.is_active = 1';
                $where .= ' AND category.is_active = 1';
            }

            // ACL
            $where .= $this->getAclWhereSql('Channel', 'channel');
            $where .= $this->getAclWhereSql('Catalog', 'catalog');
            $where .= $this->getAclWhereSql('Category', 'category');

            // prepare categories ids
            $ids = implode("', '", $categories);

            $sql
                = "SELECT
                  channel.id             AS channelId,
                  channel.name           AS channelName,
                  channel.code           AS channelCode,
                  channel.currencies     AS channelCurrencies,
                  channel.locales        AS channelLocales,
                  channel.is_active      AS channelIsActive,
                  catalog.id             AS catalogId,
                  catalog.name           AS catalogName,
                  catalog.is_active      AS catalogIsActive,
                  category.id            AS categoryId,
                  category.name          AS categoryName,
                  category.is_active     AS categoryIsActive
                FROM channel as channel
                JOIN catalog as catalog 
                  ON catalog.id=channel.catalog_id AND channel.deleted=0
                JOIN category as category 
                  ON category.id=catalog.category_id AND category.deleted=0
                WHERE channel.deleted = 0 AND catalog.category_id IN ('{$ids}') {$where}";
            $sth = $this->getEntityManager()->getPDO()->prepare($sql);
            $sth->execute();

            $result = $sth->fetchAll(PDO::FETCH_ASSOC);

            // prepare result
            if (!empty($result)) {
                // prepare params
                $channelService = $this->getInjection('serviceFactory')->create('Channel');

                foreach ($result as $k => $row) {
                    if (!empty($row['channelLocales'])) {
                        $result[$k]['channelLocales'] = $channelService
                            ->prepareLocales(Json::decode($row['channelLocales'], true));
                    } else {
                        $result[$k]['channelLocales'] = [];
                    }

                    if (!empty($row['channelCurrencies'])) {
                        $result[$k]['channelCurrencies'] = Json::decode($row['channelLocales'], true);
                    } else {
                        $result[$k]['channelCurrencies'] = [];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param string $productId
     * @param string $channelId
     * @param array  $attributeData
     *
     * @return string
     * @throws BadRequest
     * @throws Forbidden
     */
    protected function createChannelProductAttributeValue(
        string $productId,
        string $channelId,
        array $attributeData
    ): string {
        /** @var ChannelProductAttributeValue $service */
        $service = $this->getServiceFactory()->create('ChannelProductAttributeValue');

        // prepare data
        $data = [
            'productId'   => $productId,
            'channelId'   => $channelId,
            'attributeId' => $attributeData['attributeId']
        ];

        return $service->createValue($data, false);
    }

    /**
     * Prepare data Channels for output
     *
     * @param array $data
     *
     * @return array
     */
    protected function prepareGetChannels(array $data): array
    {
        $result = [];
        foreach ($data as $key => $row) {
            if (!isset($result[$row['channelId']])) {
                $result[$row['channelId']] = $row;

                $result[$row['channelId']]['channelIsActive'] = (bool)$row['channelIsActive'];
                $result[$row['channelId']]['catalogIsActive'] = (bool)$row['catalogIsActive'];
                $result[$row['channelId']]['categoryIsActive'] = (bool)$row['categoryIsActive'];
            }
        }

        return array_values($result);
    }

    /**
     * Return formatted attribute data for get actions
     *
     * @param $data
     *
     * @return array
     */
    protected function formatAttributeData($data)
    {
        // MultiLang fields name
        $multiLangValue = $this->getMultiLangName('value');
        $multiLangTypeValue = $this->getMultiLangName('type_value');

        foreach ($data as $key => $attribute) {
            //Prepare attribute
            $data[$key] = $this->prepareAttributeValue($attribute, $multiLangValue, $multiLangTypeValue);
        }

        return $data;
    }

    /**
     * Prepare attribute data
     *
     * @param array  $attribute
     * @param array  $multiLangValue
     * @param array  $multiLangTypeValue
     * @param string $prefix
     *
     * @return array
     */
    protected function prepareAttributeValue($attribute, $multiLangValue, $multiLangTypeValue, $prefix = '')
    {
        $type = 'type';
        $isRequired = 'isRequired';

        if (!empty($prefix)) {
            $type = $prefix . ucfirst($type);
            $isRequired = $prefix . ucfirst($isRequired);
        }
        $attribute[$isRequired] = (bool)$attribute[$isRequired];
        $value = $multiLangValue[0]['alias'];
        $typeValue = $multiLangTypeValue[0]['alias'];
        switch ($attribute[$type]) {
            case 'int':
                $attribute[$value] = !is_null($attribute[$value]) ? (int)$attribute[$value] : null;
                break;
            case 'bool':
                $attribute[$value] = !is_null($attribute[$value]) ? (bool)$attribute[$value] : null;
                break;
            case 'float':
                $attribute[$value] = !is_null($attribute[$value]) ? (float)$attribute[$value] : null;
                break;
            case 'multiEnum':
            case 'array':
                $attributeValue = [];
                if (!empty($attribute[$value])) {
                    $attributeValue = Json::decode($attribute[$value], true);
                }
                $attribute[$value] = $attributeValue;
                $attribute[$typeValue] = !is_null($attribute[$typeValue]) ? (array)$attribute[$typeValue] : null;
                break;
            case 'enum':
                $attribute[$typeValue] = !is_null($attribute[$typeValue]) ? (array)$attribute[$typeValue] : [];
                break;
            // Serialize MultiLang fields
            case 'multiEnumMultiLang':
            case 'arrayMultiLang':
                foreach ($multiLangValue as $key => $field) {
                    if (!is_null($attribute[$field['alias']])) {
                        $attribute[$field['alias']] = is_string($attribute[$field['alias']])
                            ?
                            json_decode($attribute[$field['alias']])
                            :
                            $attribute[$field['alias']];
                    } else {
                        $attribute[$field['alias']] = [];
                    }

                    $feild = $multiLangTypeValue[$key]['alias'];
                    if (!is_null($attribute[$feild])) {
                        $attribute[$feild] = is_string($attribute[$feild])
                            ?
                            json_decode($attribute[$feild])
                            :
                            $attribute[$feild];
                    } else {
                        $attribute[$feild] = null;
                    }
                }
                break;
            case 'enumMultiLang':
                foreach ($multiLangTypeValue as $field) {
                    if (!is_null($attribute[$field['alias']])) {
                        $attribute[$field['alias']] = is_string($attribute[$field['alias']])
                            ?
                            json_decode($attribute[$field['alias']])
                            :
                            $attribute[$field['alias']];
                    } else {
                        $attribute[$field['alias']] = [];
                    }
                }
                break;
        }

        if (isset($attribute['isCustom'])) {
            $attribute['isCustom'] = (bool)$attribute['isCustom'];
        }
        if (isset($attribute['attributeGroupOrder'])) {
            $attribute['attributeGroupOrder'] = (int)$attribute['attributeGroupOrder'];
        }
        // prepare isMultiChannel
        if (isset($attribute['attributeIsMultiChannel'])) {
            $attribute['attributeIsMultiChannel'] = (bool)$attribute['attributeIsMultiChannel'];
        }

        // prepare attribute group
        if (empty($attribute['attributeGroupId'])) {
            $attribute['attributeGroupId'] = 'no_group';
            $attribute['attributeGroupName'] = 'No group';
            $attribute['attributeGroupOrder'] = 999;
        }

        return $attribute;
    }

    /**
     * Save data to db
     *
     * @param Entity $entity
     * @param array  $data
     *
     * @return Entity
     * @throws Error
     */
    protected function save(Entity $entity, $data)
    {
        $entity->set($data);
        if ($this->storeEntity($entity)) {
            $this->prepareEntityForOutput($entity);

            return $entity;
        }

        throw new Error();
    }

    /**
     * Get active parent category id for category from DB
     *
     * @param $categoryId
     *
     * @return array
     */
    protected function getDBParentCategory(string $categoryId): array
    {
        $pdo = $this->getEntityManager()->getPDO();
        $sql
            = "SELECT
                  c.category_parent_id AS categoryId
                FROM category AS c
                  JOIN 
                  category AS c2 on c2.id = c.category_parent_id AND c2.deleted = 0 AND c2.is_active = 1
                WHERE 
                c.deleted = 0 AND c.is_active = 1 AND c.id =" . $pdo->quote($categoryId) . ";";
        $sth = $pdo->prepare($sql);
        $sth->execute();
        $result = $sth->fetchAll(PDO::FETCH_ASSOC);

        return (!empty($result)) ? array_column($result, 'categoryId') : [];
    }

    /**
     * Get DB count of item in products data
     *
     * @param string $productId
     *
     * @return int
     */
    protected function getDbCountItemInProducts(string $productId): int
    {
        // prepare data
        $pdo = $this->getEntityManager()->getPDO();
        $where = $this->getAclWhereSql('Product', 'p');

        // prepare SQL
        $sql
            = "SELECT
                  COUNT(p.id) as count
                FROM
                  product AS p
                WHERE
                 p.deleted = 0
                AND p.id IN (SELECT bundle_product_id FROM product_type_bundle
                                                    WHERE product_id = " . $pdo->quote($productId) . " $where)";
        $sth = $pdo->prepare($sql);
        $sth->execute();

        // get DB data
        $data = $sth->fetchAll(PDO::FETCH_ASSOC);

        return (isset($data[0]['count'])) ? (int)$data[0]['count'] : 0;
    }

    /**
     * Get DB count of item in products data
     *
     * @param string  $productId
     * @param Request $request
     *
     * @return array
     */
    protected function getDbItemInProducts(string $productId, Request $request): array
    {
        // prepare data
        $limit = (int)$request->get('maxSize');
        $offset = (int)$request->get('offset');
        $sortOrder = ($request->get('asc') == 'true') ? 'ASC' : 'DESC';
        $sortColumn = (in_array($request->get('sortBy'), ['name', 'type'])) ? $request->get('sortBy') : 'name';
        $where = $this->getAclWhereSql('Product', 'p');

        // prepare PDO
        $pdo = $this->getEntityManager()->getPDO();

        // prepare SQL
        $sql
            = "SELECT
                  p.id   AS id,
                  p.name AS name,
                  p.type AS type
                FROM
                  product AS p
                WHERE
                 p.deleted = 0
                AND p.id IN (SELECT bundle_product_id FROM product_type_bundle
                                                    WHERE product_id = " . $pdo->quote($productId) . " $where)
                ORDER BY p." . $sortColumn . " " . $sortOrder . "
                LIMIT " . $limit . " OFFSET " . $offset;
        $sth = $pdo->prepare($sql);
        $sth->execute();

        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * After delete action
     *
     * @param Entity $entity
     *
     * @return void
     */
    protected function afterDelete(Entity $entity): void
    {
        $this->deleteProductTypes([$entity->get('id')]);
    }

    /**
     * After mass delete action
     *
     * @param array $idList
     *
     * @return void
     */
    protected function afterMassRemove(array $idList): void
    {
        $this->deleteProductTypes($idList);
    }

    /**
     * Delete product types
     *
     * @param array $idList
     *
     * @return void
     */
    protected function deleteProductTypes(array $idList): void
    {
        // delete type bundle
        $this->getServiceFactory()->create('ProductTypeBundle')->deleteByProductId($idList);

        // delete type package
        $this->getServiceFactory()->create('ProductTypePackage')->deleteByProductId($idList);
    }

    /**
     * Find linked AssociationMainProduct
     *
     * @param string $id
     * @param array  $params
     *
     * @return array
     * @throws Forbidden
     */
    protected function findLinkedEntitiesAssociatedMainProducts(string $id, array $params): array
    {
        // check acl
        if (!$this->getAcl()->check('Association', 'read')) {
            throw new Forbidden();
        }

        return [
            'list'  => $this->getDBAssociationMainProducts($id, '', $params),
            'total' => $this->getDBTotalAssociationMainProducts($id, '')
        ];

    }

    /**
     * Get AssociationMainProducts from DB
     *
     * @param string $productId
     * @param string $wherePart
     * @param array  $params
     *
     * @return array
     */
    protected function getDBAssociationMainProducts(string $productId, string $wherePart, array $params): array
    {
        // prepare limit
        $limit = '';
        if (!empty($params['maxSize'])) {
            $limit = ' LIMIT ' . (int)$params['maxSize'];
            $limit .= ' OFFSET ' . (empty($params['offset']) ? 0 : (int)$params['offset']);
        }

        //prepare sort
        $sortOrder = ($params['asc'] === true) ? 'ASC' : 'DESC';
        $orderColumn = ['relatedProduct', 'association'];
        $sortColumn = in_array($params['sortBy'], $orderColumn) ? $params['sortBy'] . '.name' : 'relatedProduct.name';

        // prepare query
        $sql
            = "SELECT
                  ap.id,
                  ap.association_id   AS associationId,
                  association.name    AS associationName,
                  p_main.id           AS mainProductId,
                  p_main.name         AS mainProductName,
                  relatedProduct.id   AS relatedProductId,
                  relatedProduct.name AS relatedProductName,
                  i.image_file_id         AS relatedProductImageId,
                  i.image_link       AS relatedProductImageLink
                FROM associated_product AS ap
                  JOIN product AS relatedProduct 
                    ON relatedProduct.id = ap.related_product_id AND relatedProduct.deleted = 0
                  LEFT JOIN product_image_linker as pil
                    ON pil.product_id = relatedProduct.id AND pil.id = (
                      SELECT id
                      FROM product_image_linker
                      WHERE product_id = pil.product_id AND deleted = 0
                      ORDER BY sort_order, id
                      LIMIT 1
                    )
                  LEFT JOIN image as i
                    ON i.id = pil.image_id AND i.deleted = 0
                  JOIN product AS p_main 
                    ON p_main.id = ap.related_product_id AND p_main.deleted = 0
                  JOIN association 
                    ON association.id = ap.association_id AND association.deleted = 0
                WHERE ap.deleted = 0 
                  AND ap.main_product_id = :id "
            . $wherePart
            . "ORDER BY " . $sortColumn . " " . $sortOrder
            . $limit;

        $sth = $this->getEntityManager()->getPDO()->prepare($sql);
        $sth->execute([':id' => $productId]);

        return $sth->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get total AssociationMainProducts
     *
     * @param string $productId
     * @param string $wherePart
     *
     * @return int
     */
    protected function getDBTotalAssociationMainProducts(string $productId, string $wherePart): int
    {
        // prepare query
        $sql
            = "SELECT
                  COUNT(ap.id)                  
                FROM associated_product AS ap
                  JOIN product AS p_rel 
                    ON p_rel.id = ap.related_product_id AND p_rel.deleted = 0
                  JOIN product AS p_main 
                    ON p_main.id = ap.related_product_id AND p_main.deleted = 0
                  JOIN association 
                    ON association.id = ap.association_id AND association.deleted = 0
                WHERE ap.deleted = 0 AND  ap.main_product_id = :id " . $wherePart;

        $sth = $this->getEntityManager()->getPDO()->prepare($sql);
        $sth->execute([':id' => $productId]);

        return (int)$sth->fetchColumn();
    }

    /**
     * Get ProductAttributeValue service
     *
     * @return ProductAttributeValue
     */
    protected function getProductAttributeValueService(): ProductAttributeValue
    {
        return $this->getServiceFactory()->create('ProductAttributeValue');
    }

    /**
     * Duplicate links for product
     *
     * @param Entity $product
     * @param Entity $duplicatingProduct
     */
    protected function duplicateLinks(Entity $product, Entity $duplicatingProduct)
    {
        $repository = $this->getRepository();

        foreach ($this->getDuplicatingLinkList() as $link) {
            $methodName = 'duplicateLinks' . ucfirst($link);
            // check if method exists for duplicate this $link
            if (method_exists($this, $methodName)) {
                $this->{$methodName}($product, $duplicatingProduct);
            } else {
                // find liked entities
                foreach ($repository->findRelated($duplicatingProduct, $link) as $linked) {
                    switch ($product->getRelationType($link)) {
                        case Entity::HAS_MANY:
                            // create and relate new entity
                            $this->linkCopiedEntity($product, $link, $linked);

                            break;
                        case Entity::MANY_MANY:
                            // create new relation
                            $repository->relate($product, $link, $linked);

                            break;
                    }
                }
            }
        }
    }

    /**
     * Get Duplicating Link List
     *
     * @return array
     */
    protected function getDuplicatingLinkList(): array
    {
        // event call
        $this->triggered('Product', 'getDuplicatingLinkList', ['productService' => $this]);

        return $this->duplicatingLinkList;
    }

    /**
     * Create new entity from $linked entity and relate to Product
     *
     * @param Entity $product
     * @param string $link
     * @param Entity $linked
     */
    protected function linkCopiedEntity(Entity $product, string $link, Entity $linked)
    {
        // get new Entity
        $newEntity = $this->getEntityManager()->getEntity($linked->getEntityType());

        // prepare data
        $data = [
            '_duplicatingEntityId'                          => $linked->get('id'),
            'id'                                            => null,
            $product->getRelationParam($link, 'foreignKey') => $product->get('id')
        ];

        // set data to new entity
        $newEntity->set(array_merge($linked->toArray(), $data));
        // save entity
        $this->getEntityManager()->saveEntity($newEntity);
    }

    /**
     * Duplicate ChannelProductAttributeValue
     *
     * @param Entity $product
     * @param Entity $duplicatingProduct
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     */
    protected function duplicateLinksChannelProductAttributeValues(Entity $product, Entity $duplicatingProduct)
    {
        $attributeService = $this->getServiceFactory()->create('ChannelProductAttributeValue');

        $productAttributes = $product->getProductAttributes();

        foreach ($this->getChannelAttributes($duplicatingProduct->get('id')) as $row) {
            foreach ($row['attributes'] as $attribute) {
                $key = array_search(
                    $attribute['attributeId'],
                    array_column($productAttributes->toArray(), 'attributeId')
                );

                if ($key !== false) {
                    $data = [
                        'productAttributeId' => $productAttributes[$key]->get('id'),
                        'channelId'          => $row['channelId'],
                    ];

                    // set value
                    if (isset($attribute['attributeValue'])) {
                        $data['value'] = $attribute['attributeValue'];
                    }

                    // set value multiLang
                    foreach ($this->getConfig()->get('inputLanguageList') as $language) {
                        $lang = strtolower($language);
                        if (isset($attribute['attributeValue' . $lang])) {
                            $data['value' . $lang] = $attribute['attributeValue' . $lang];
                        }
                    }
                    $attributeService->createEntity((object)$data);
                }
            }
        }
    }

    /**
     * Duplicate AssociationMainProducts
     *
     * @param Entity $product
     * @param Entity $duplicatingProduct
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    protected function duplicateLinksAssociationMainProducts(Entity $product, Entity $duplicatingProduct)
    {
        /** @var AssociatedProduct $associationProductService */
        $associationProductService = $this->getServiceFactory()->create('AssociatedProduct');

        // find AssociatedProducts
        $associationProducts = $this->findLinkedEntitiesAssociationMainProducts($duplicatingProduct->get('id'), []);

        foreach ($associationProducts['list'] as $associationProduct) {
            // prepare data
            $data = [
                'mainProductId'    => $product->get('id'),
                'relatedProductId' => $associationProduct['relatedProductId'],
                'associationId'    => $associationProduct['associationId']
            ];
            // create new AssociatedProducts
            $associationProductService->createAssociatedProduct($data);
        }
    }

    /**
     * Duplicate BundleProducts
     *
     * @param Entity $product
     * @param Entity $duplicatingProduct
     *
     */
    protected function duplicateLinksBundleProducts(Entity $product, Entity $duplicatingProduct)
    {
        if ($duplicatingProduct->get('type') === 'bundleProduct') {
            /** @var ProductTypeBundle $bundleService */
            $bundleService = $this->getServiceFactory()->create('ProductTypeBundle');

            // find bundles
            $bundles = $bundleService->getBundleProducts($duplicatingProduct->get('id'));
            // create new bundles
            foreach ($bundles as $bundle) {
                $bundleService->create($product->get('id'), $bundle['productId'], $bundle['amount']);
            }
        }
    }

    /**
     * Duplicate ProductTypePackages
     *
     * @param Entity $product
     * @param Entity $duplicatingProduct
     *
     */
    protected function duplicateLinksProductTypePackages(Entity $product, Entity $duplicatingProduct)
    {
        if ($duplicatingProduct->get('type') === 'packageProduct') {
            /** @var ProductTypePackage $productPackageService */
            $productPackageService = $this->getServiceFactory()->create('ProductTypePackage');

            // find ProductPackage
            $productPackage = $productPackageService->getPackageProduct($duplicatingProduct->get('id'));

            // create new productPackage
            if (!is_null($productPackage['id'])) {
                $productPackageService->update($product->get('id'), $productPackage);
            }
        }
    }

    /**
     * Prepare value by type
     *
     * @param string $type
     * @param string $value
     *
     * @return mixed
     */
    protected function prepareValue(string $type, string $value)
    {
        // prepare result
        $result = null;

        if (!is_null($value)) {
            switch ($type) {
                case 'int':
                    $result = (int)$value;
                    break;
                case 'bool':
                    $result = (bool)$value;
                    break;
                case 'float':
                    $result = (float)$value;
                    break;
                case 'multiEnum':
                    if (!empty($value)) {
                        $result = Json::decode($value, true);
                    }
                    break;
                case 'array':
                    if (!empty($value)) {
                        $result = Json::decode($value, true);
                    }
                    break;
                case 'multiEnumMultiLang':
                    if (!empty($value)) {
                        $result = Json::decode($value, true);
                    }
                    break;
                case 'arrayMultiLang':
                    if (!empty($value)) {
                        $result = Json::decode($value, true);
                    }
                    break;
                default:
                    $result = $value;
                    break;
            }
        }

        return $result;
    }

    /**
     * Before create entity method
     *
     * @param Entity $entity
     * @param        $data
     */
    protected function beforeCreateEntity(Entity $entity, $data)
    {
        if (isset($data->_duplicatingEntityId)) {
            $entity->isDuplicate = true;
        }
    }
}
