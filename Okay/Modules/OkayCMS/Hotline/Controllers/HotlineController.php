<?php


namespace Okay\Modules\OkayCMS\Hotline\Controllers;


use Aura\Sql\ExtendedPdo;
use Okay\Controllers\AbstractController;
use Okay\Core\QueryFactory;
use Okay\Core\Router;
use Okay\Core\Routes\ProductRoute;
use Okay\Entities\CategoriesEntity;
use Okay\Helpers\XmlFeedHelper;
use Okay\Modules\OkayCMS\Hotline\Helpers\HotlineHelper;
use Okay\Modules\OkayCMS\Hotline\Init\Init;
use PDO;

class HotlineController extends AbstractController
{
    public function render(
        CategoriesEntity $categoriesEntity,
        QueryFactory $queryFactory,
        ExtendedPdo $pdo,
        HotlineHelper $hotlineHelper,
        XmlFeedHelper $feedHelper
    ) {
        
        if (!empty($this->currencies)) {
            $this->design->assign('main_currency', reset($this->currencies));
        }

        $sql = $queryFactory->newSqlQuery();
        $sql->setStatement('SET SQL_BIG_SELECTS=1');
        $sql->execute();

        $sql = $queryFactory->newSqlQuery();
        $sql->setStatement("SELECT id FROM " . CategoriesEntity::getTable() . " WHERE ".Init::TO_FEED_FIELD."=1");
        
        $categoriesToFeed = $sql->results('id');
        $uploadCategories = $feedHelper->addAllChildrenToList($categoriesToFeed);
        
        $this->design->assign('all_categories', $categoriesEntity->find());

        $this->response->setContentType(RESPONSE_XML);
        $this->response->sendHeaders();
        $this->response->sendStream($this->design->fetch('feed_head.xml.tpl'));
        
        // На всякий случай наполним кеш роутов
        Router::generateRouterCache();

        // Запрещаем выполнять запросы в БД во время генерации урла т.к. мы работаем с небуферизированными запросами
        ProductRoute::setNotUseSqlToGenerate();

        // Увеличиваем лимит ф-ции GROUP_CONCAT()
        $query = $queryFactory->newSqlQuery();
        $query->setStatement('SET SESSION group_concat_max_len = 1000000;')->execute();
        
        // Для экономии памяти работаем с небуферизированными запросами
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $query = $hotlineHelper->getQuery($uploadCategories);

        $prevProductId = null;
        while ($product = $query->result()) {
            $product = $feedHelper->attachFeatures($product);
            $product = $feedHelper->attachProductImages($product);

            $addVariantUrl = false;
            if ($prevProductId === $product->product_id) {
                $addVariantUrl = true;
            }
            
            $item = $hotlineHelper->getItem($product, $addVariantUrl);
            $xmlProduct = $feedHelper->compileItem($item, 'item');
            $this->response->sendStream($xmlProduct);
        }

        $this->response->sendStream($this->design->fetch('feed_footer.xml.tpl'));
    }
}
