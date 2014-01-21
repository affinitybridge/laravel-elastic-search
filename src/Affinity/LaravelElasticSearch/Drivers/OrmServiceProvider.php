<?php namespace Affinity\LaravelElasticSearch\Drivers;

use Illuminate\Support\ServiceProvider;
use FOS\ElasticaBundle\Doctrine\ORM\Provider;
use FOS\ElasticaBundle\Doctrine\ORM\Listener;
use FOS\ElasticaBundle\Doctrine\RepositoryManager;
use FOS\ElasticaBundle\Doctrine\ORM\ElasticaToModelTransformer;

class OrmServiceProvider extends ServiceProvider {

  /**
   * Register the service provider.
   *
   * @return void
   */
  public function register() {
    $this->app['laravel-elastic-search.provider.prototype.orm'] = function ($app, $params) {
      list($persister, $model, $options) = $params;
      return new Provider($persister, $model, $options, $app['doctrine.registry']);
    };

    $this->app['laravel-elastic-search.listener.prototype.orm'] = function ($app, $params) {
      list($persister, $model, $events, $id) = $params;
      return new Listener($persister, $model, $events, $id);
    };

    $this->app['laravel-elastic-search.elastica_to_model_transformer.prototype.orm'] = function ($app, $params) {
      list($unknown, $model, $options) = $params;
      $trans = new ElasticaToModelTransformer($app['doctrine.registry'], $model, $options);
      $trans->setPropertyAccessor($app['laravel-elastic-search.property_accessor']);
      return $trans;
    };

    $this->app->singleton('laravel-elastic-search.manager.orm', function ($app) {
      return new RepositoryManager($app['doctrine.registry'], $app['doctrine.annotation_reader']);
    });
  }

}
