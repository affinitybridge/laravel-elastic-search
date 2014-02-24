<?php namespace Affinity\LaravelElasticSearch;

use Illuminate\Support\ServiceProvider;
use JMS\Serializer\SerializerBuilder;
use Doctrine\Common\Annotations\AnnotationRegistry;

use FOS\ElasticaBundle\Finder\TransformedFinder;
use FOS\ElasticaBundle\Resetter;
use FOS\ElasticaBundle\IndexManager;
use FOS\ElasticaBundle\Subscriber\PaginateElasticaQuerySubscriber;

class LaravelElasticSearchServiceProvider extends ServiceProvider {

  private $default_index;
  private $default_client;
  private $default_manager;
  private $indexConfigs     = array();
  private $typeFields       = array();
  private $serializerConfig = array();
  private $implementations  = array();
  private $indexes          = array();
  private $indexIdsByName   = array();
  private $drivers = array(
    'orm' => 'Affinity\LaravelElasticSearch\Drivers\OrmServiceProvider',
  );

  /**
   * Indicates if loading of the provider is deferred.
   *
   * @var bool
   */
  protected $defer = false;

  /**
   * Bootstrap the application events.
   *
   * @return void
   */
  public function boot() {
    // TODO: Move this out of here.
    $base_path = base_path();
    AnnotationRegistry::registerAutoloadNamespace('FOS\ElasticaBundle\Configuration', "$base_path/vendor/friendsofsymfony/elastica-bundle");
    AnnotationRegistry::registerAutoloadNamespace('JMS\Serializer\Annotation', "$base_path/vendor/jms/serializer/src");

    if ($this->app['config']->get('laravel-elastic-search::disabled', FALSE)) {
      return;
    }

    $this->loadIndexes();
    $this->transformerPass();
    $this->providersPass();
    $this->doctrineSubscribersPass();
  }

  protected function loadIndexes() {
    $app = $this->app;

    foreach ($this->indexes as $name => $index) {
      $indexId = sprintf('laravel-elastic-search.index.%s', $name);
      $typePrototypeConfig = isset($index['type_prototype']) ? $index['type_prototype'] : array();
      $this->loadTypes(isset($index['types']) ? $index['types'] : array(), $name, $indexId, $typePrototypeConfig);
      $indexId = $this->indexConfigs[$name]['index'];
      $this->indexConfigs[$name]['index'] = $app[$indexId];
    }

    $indexRefsByName = array_map(function ($id) use ($app) {
      return $app[$id];
    }, $this->indexIdsByName);

    $this->registerIndexManager($indexRefsByName);
    $this->registerResetter($this->indexConfigs);

    $app['laravel-elastic-search.client'] = $app[sprintf('laravel-elastic-search.client.%s', $this->default_client)];
    $app['laravel-elastic-search.index'] = $app[sprintf('laravel-elastic-search.index.%s', $this->default_index)];

    $this->createDefaultManagerAlias($this->default_manager);
  }

  protected function transformerPass() {
    if (!isset($this->app['laravel-elastic-search.elastica_to_model_transformer.collection'])) {
      return;
    }

    $transformers = array();

    foreach ($this->app['laravel-elastic-search.elastica_to_model_transformers'] as $id => $tag) {
      if (empty($tag['index']) || empty($tag['type'])) {
        throw new \InvalidArgumentException('The Transformer must have both a type and an index defined.');
      }

      $transformers[$tag['index']][$tag['type']] = $this->app[$id];
    }

    foreach ($transformers as $index => $indexTransformers) {
      $transformerId = sprintf('laravel-elastic-search.elastica_to_model_transformer.collection.%s', $index);

      if (!isset($this->app[$transformerId])) {
        continue;
      }

      $this->app->singleton($transformerId, function ($app) use ($indexTransformers) {
        $transformerCollectorClass = $app['config']->get('laravel-elastic-search::parameters.ElasticaToModelTransformerCollectionClass', NULL);
        return new $transformerCollectorClass($indexTransformers);
      });
    }
  }

  protected function providersPass() {
    if (!isset($this->app['laravel-elastic-search.provider_registry'])) {
      return;
    }

    // Infer the default index name from the service alias
    $defaultIndex = 'default';//substr($this->app->getAlias('laravel-elastic-search.index'), 29);

    foreach ($this->app['laravel-elastic-search.providers'] as $providerId => $tag) {
      $index = $type = null;
      $provider = $this->app[$providerId];
      $class = get_class($provider);

      if (!$class || !$this->isProviderImplementation($class)) {
        throw new \InvalidArgumentException(sprintf('Elastica provider "%s" with class "%s" must implement ProviderInterface.', $providerId, $class));
      }

      if (!isset($tag['type'])) {
        throw new \InvalidArgumentException(sprintf('Elastica provider "%s" must specify the "type" attribute.', $providerId));
      }

      $index = isset($tag['index']) ? $tag['index'] : $defaultIndex;
      $type = $tag['type'];

      $this->app->extend('laravel-elastic-search.provider_registry', function ($registry) use ($index, $type, $providerId) {
        $registry->addProvider($index, $type, $providerId);
        return $registry;
      });
    }
  }

  /**
   * TODO: Move this out of here.
   */
  protected function doctrineSubscribersPass() {
    foreach ($this->app['doctrine.event_subscribers'] as $listenerId) {
      $this->app['doctrine']->getEventManager()->addEventSubscriber($this->app[$listenerId]);
    }
  }

  /**
   * Returns whether the class implements ProviderInterface.
   *
   * @param string $class
   * @return boolean
   */
  private function isProviderImplementation($class) {
    if (!isset($this->implementations[$class])) {
      $refl = new \ReflectionClass($class);
      $this->implementations[$class] = $refl->implementsInterface('FOS\ElasticaBundle\Provider\ProviderInterface');
    }
    return $this->implementations[$class];
  }

  /**
   * Register the service provider.
   *
   * @return void
   */
  public function register() {
    $this->package('affinity/laravel-elastic-search');
    $app = $this->app;

    if ($app['config']->get('laravel-elastic-search::disabled', FALSE)) {
      return;
    }

    $this->loadCommands();

    $app['serializer'] = SerializerBuilder::create()->build();

    // NOTE: Poor-man's equivalent to Symfony's "tagged services" (store a
    //       list of service ids in an array on the container for lookup later).
    $app['laravel-elastic-search.elastica_to_model_transformers'] = array();
    $app['laravel-elastic-search.providers'] = array();
    $app['doctrine.event_subscribers'] = array();
    $app['laravel-elastic-search.drivers'] = array_keys($this->drivers);

    $this->loadServices();

    // Prefix indexes to allow for multiple instances of site.
    $prefix = $app['config']->get('laravel-elastic-search::prefix', NULL);
    $this->indexes = [];
    foreach ($app['config']->get('laravel-elastic-search::indexes', array()) as $name => $index) {
      if ($prefix) $name = "{$prefix}-{$name}";
      $this->indexes[$name] = $index;
    }

    $clients = $app['config']->get('laravel-elastic-search::clients', array());
    $serializer = $app['config']->get('laravel-elastic-search::serializer', NULL);
    $this->default_client = $app['config']->get('laravel-elastic-search::default_client', array());
    $this->default_index = $app['config']->get('laravel-elastic-search::default_index', array());
    $this->default_manager = $app['config']->get('laravel-elastic-search::default_manager', array());

    if (empty($clients) || empty($this->indexes)) {
      throw new \InvalidArgumentException('You must define at least one client and one index');
    }

    if (empty($this->default_client)) {
      $keys = array_keys($clients);
      $this->default_client = reset($keys);
    }

    if (empty($this->default_index)) {
      $keys = array_keys($this->indexes);
      $this->default_index = reset($keys);
    }

    $clientIdsByName = $this->loadClients($clients);
    $this->serializerConfig = $serializer;
    $this->indexIdsByName = $this->registerIndexes($this->indexes, $clientIdsByName, $this->default_client);

    foreach ($this->drivers as $driver => $class) {
      $this->app->register($class);
    }
  }

  protected function loadCommands() {
    $this->commands(array(
      'Affinity\LaravelElasticSearch\Commands\PopulateCommand',
      'Affinity\LaravelElasticSearch\Commands\ResetCommand',
      'Affinity\LaravelElasticSearch\Commands\SearchCommand',
    ));
  }

  protected function loadServices() {
    $this->app->singleton('laravel-elastic-search.logger', function ($app) {
      $debug = $app['config']->get('debug', FALSE);
      $loggerClass = $app['config']->get('laravel-elastic-search::parameters.LoggerClass', NULL);
      return new $loggerClass(Log::getMonolog(), $debug);
    });

    $this->app->singleton('laravel-elastic-search.data_collector', function ($app) {
      $dataCollectorClass = $app['config']->get('laravel-elastic-search::parameters.DataCollectorClass', NULL);
      return new $dataCollectorClass($app['laravel-elastic-search.logger']);
    });

    $this->app->singleton('laravel-elastic-search.elastica_to_model_transformer.collection', function ($app) {
      $transformerCollectorClass = $app['config']->get('laravel-elastic-search::parameters.ElasticaToModelTransformerCollectionClass', NULL);
      return new $transformerCollectorClass(array());
    });

    $this->app->singleton('laravel-elastic-search.provider_registry', function ($app) {
      $providerRegistryClass = $app['config']->get('laravel-elastic-search::parameters.ProviderRegistryClass', NULL);
      $registry = new $providerRegistryClass();
      return $registry;
    });

    $this->app->singleton('laravel-elastic-search.paginator.subscriber', function ($app) {
      // TODO: Should be 'tagged' with knp_paginator.subscriber.
      return new PaginateElasticaQuerySubscriber();
    });

    $this->app->singleton('laravel-elastic-search.property_accessor', function ($app) {
      $propertyAccessorClass = $app['config']->get('laravel-elastic-search::parameters.PropertyAccessorClass', NULL);
      return new $propertyAccessorClass();
    });
  }

  /**
   * Loads the configured clients.
   *
   * @param array $clients An array of clients configurations
   * @return array
   */
  protected function loadClients(array $clients) {
    $clientIds = array();
    $this->app['laravel-elastic-search.clients'] = array();

    $clientClass = $this->app['config']->get('laravel-elastic-search::parameters.ClientClass', NULL);
    if (!$clientClass) throw new InvalidArgumentException("You must define a laravel-elastic-search parameter: ClientClass");

    foreach ($clients as $name => $clientConfig) {
      $clientId = sprintf('laravel-elastic-search.client.%s', $name);

      $this->app->singleton($clientId, function ($app) use ($clientClass, $clientConfig) {
        $client = new $clientClass($clientConfig);

        $logger = isset($clientConfig['servers']) && !empty($clientConfig['servers']) ? $clientConfig['servers'][0]['logger'] : FALSE;
        if (false !== $logger) {
          $client->setLogger($app[$logger]);
        }

        return $client;
      });

      // NOTE: Poor-man's equivalent to Symfony's "tagged services" (store a
      //       list of service ids in an array on the container for lookup later).
      $this->app->extend('laravel-elastic-search.clients', function ($tags, $app) use ($clientId, $clientConfig) {
        $tags[] = [$clientId, $clientConfig];
        return $tags;
      });

      $clientIds[$name] = $clientId;
    }

    return $clientIds;
  }

  /**
   * Loads the configured indexes.
   *
   * @param array $indexes An array of indexes configurations
   * @param array $clientIdsByName
   * @param $defaultClientName
   * @throws \InvalidArgumentException
   * @return array
   */
  protected function registerIndexes(array $indexes, array $clientIdsByName, $defaultClientName) {
    $indexIds = array();

    // $indexClass = $this->app['config']->get('laravel-elastic-search::parameters.IndexClass', NULL);
    // if (!$indexClass) throw new InvalidArgumentException("You must define a laravel-elastic-search parameter: IndexClass");

    foreach ($indexes as $name => $index) {
      if (isset($index['client'])) {
        $clientName = $index['client'];
        if (!isset($clientIdsByName[$clientName])) {
          throw new InvalidArgumentException(sprintf('The elastica client with name "%s" is not defined', $clientName));
        }
      } else {
        $clientName = $defaultClientName;
      }

      $clientId = $clientIdsByName[$clientName];
      $indexId = sprintf('laravel-elastic-search.index.%s', $name);
      $indexName = isset($index['index_name']) ? $index['index_name'] : $name;
      $indexDefArgs = array($indexName);

      $this->app->singleton($indexId, function ($app) use ($clientId, $indexName) {
        return $app[$clientId]->getIndex($indexName);
      });

      $indexIds[$name] = $indexId;
      $this->indexConfigs[$name] = array(
        'index' => $indexId,
        'config' => array(
          'mappings' => array()
        )
      );

      if (isset($index['finder']) && $index['finder']) {
        $this->loadIndexFinder($name, $indexId);
      }
      if (!empty($index['settings'])) {
        $this->indexConfigs[$name]['config']['settings'] = $index['settings'];
      }
    }

    return $indexIds;
  }

  /**
   * Loads the configured index finders.
   *
   * @param string $name The index name
   * @param string $indexId The index service identifier
   * @return string
   */
  protected function loadIndexFinder($name, $indexId) {
    /* Note: transformer services may conflict with "collection.index", if
     * an index and type names were "collection" and an index, respectively.
     */
    $transformerId = sprintf('laravel-elastic-search.elastica_to_model_transformer.collection.%s', $name);
    $this->app->singleton($transformerId, function ($app) {
      $transformerCollectorClass = $app['config']->get('laravel-elastic-search::parameters.ElasticaToModelTransformerCollectionClass', NULL);
      return new $transformerCollectorClass(array());
    });

    $finderId = sprintf('laravel-elastic-search.finder.%s', $name);
    $this->app->singleton($finderId, function ($app) use ($indexId, $transformerId) {
      return new TransformedFinder($app[$indexId], $app[$transformerId]);
    });

    return $finderId;
  }

  /**
   * Loads the configured types.
   *
   * @param array $types An array of types configurations
   * @param $indexName
   * @param $indexId
   * @param array $typePrototypeConfig
   * @param $serializerConfig
   */
  protected function loadTypes(array $types, $indexName, $indexId, array $typePrototypeConfig) {
    foreach ($types as $name => $type) {
      $type = self::deepArrayUnion($typePrototypeConfig, $type);
      $typeId = sprintf('%s.%s', $indexId, $name);

      $serializerConfig = $this->serializerConfig;

      $this->app->singleton($typeId,  function ($app) use ($indexId, $name, $serializerConfig, $type) {
        $type_instance = $app[$indexId]->getType($name);

        if ($serializerConfig) {
          $callbackId = sprintf('%s.%s.serializer.callback', $indexId, $name);

          $app->singleton($callbackId, function ($app) use ($serializerConfig, $type) {
            $callback = new $serializerConfig['callback_class']();
            $callback->setSerializer($app[$serializerConfig['serializer']]);
            if (isset($type['serializer']['groups'])) {
              $callback->setGroups($type['serializer']['groups']);
            }
            if (isset($type['serializer']['version'])) {
              $callback->setVersion($type['serializer']['version']);
            }
            return $callback;
          });

          $type_instance->setSerializer(array($app[$callbackId], 'serialize'));
        }

        return $type_instance;
      });

      $this->indexConfigs[$indexName]['config']['mappings'][$name] = array(
        "_source" => array("enabled" => true), // Add a default setting for empty mapping settings
      );

      if (isset($type['_id'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['_id'] = $type['_id'];
      }
      if (isset($type['_source'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['_source'] = $type['_source'];
      }
      if (isset($type['_boost'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['_boost'] = $type['_boost'];
      }
      if (isset($type['_routing'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['_routing'] = $type['_routing'];
      }
      if (isset($type['mappings']) && !empty($type['mappings'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['properties'] = $type['mappings'];
        $typeName = sprintf('%s/%s', $indexName, $name);
        $this->typeFields[$typeName] = $type['mappings'];
      }
      if (isset($type['_parent'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['_parent'] = array('type' => $type['_parent']['type']);
        $typeName = sprintf('%s/%s', $indexName, $name);
        $this->typeFields[$typeName]['_parent'] = $type['_parent'];
      }
      if (isset($type['persistence'])) {
        $this->loadTypePersistenceIntegration($type['persistence'], $typeId, $indexName, $name);
      }
      if (isset($type['index_analyzer'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['index_analyzer'] = $type['index_analyzer'];
      }
      if (isset($type['search_analyzer'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['search_analyzer'] = $type['search_analyzer'];
      }
      if (isset($type['index'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['index'] = $type['index'];
      }
      if (isset($type['_all'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['_all'] = $type['_all'];
      }
      if (!empty($type['dynamic_templates'])) {
        $this->indexConfigs[$indexName]['config']['mappings'][$name]['dynamic_templates'] = array();
        foreach ($type['dynamic_templates'] as $templateName => $templateData) {
          $this->indexConfigs[$indexName]['config']['mappings'][$name]['dynamic_templates'][] = array($templateName => $templateData);
        }
      }
    }
  }

  /**
   * Merges two arrays without reindexing numeric keys.
   *
   * @param array $array1 An array to merge
   * @param array $array2 An array to merge
   *
   * @return array The merged array
   */
  static protected function deepArrayUnion($array1, $array2) {
    foreach ($array2 as $key => $value) {
      if (is_array($value) && isset($array1[$key]) && is_array($array1[$key])) {
        $array1[$key] = self::deepArrayUnion($array1[$key], $value);
      } else {
        $array1[$key] = $value;
      }
    }

    return $array1;
  }

  /**
   * Loads the optional provider and finder for a type
   *
   * @param array $typeConfig
   * @param $typeId
   * @param $indexName
   * @param $typeName
   */
  protected function loadTypePersistenceIntegration(array $typeConfig, $typeId, $indexName, $typeName) {
    $elasticaToModelTransformerId = $this->loadElasticaToModelTransformer($typeConfig, $indexName, $typeName);
    $modelToElasticaTransformerId = $this->loadModelToElasticaTransformer($typeConfig, $indexName, $typeName);
    $objectPersisterId            = $this->loadObjectPersister($typeConfig, $typeId, $indexName, $typeName, $modelToElasticaTransformerId);

    if (isset($typeConfig['provider'])) {
      $this->loadTypeProvider($typeConfig, $objectPersisterId, $indexName, $typeName);
    }
    if (isset($typeConfig['finder'])) {
      $this->loadTypeFinder($typeConfig, $elasticaToModelTransformerId, $typeId, $indexName, $typeName);
    }
    if (isset($typeConfig['listener'])) {
      $this->loadTypeListener($typeConfig, $objectPersisterId, $indexName, $typeName);
    }
  }

  protected function loadElasticaToModelTransformer(array $typeConfig, $indexName, $typeName) {
    if (isset($typeConfig['elastica_to_model_transformer']['service'])) {
      return $typeConfig['elastica_to_model_transformer']['service'];
    }
    /* Note: transformer services may conflict with "prototype.driver", if
     * the index and type names were "prototype" and a driver, respectively.
     */
    $abstractId = sprintf('laravel-elastic-search.elastica_to_model_transformer.prototype.%s', $typeConfig['driver']);
    $serviceId = sprintf('laravel-elastic-search.elastica_to_model_transformer.%s.%s', $indexName, $typeName);

    $this->app->singleton($serviceId, function ($app) use ($abstractId, $typeConfig) {
      $args = array(NULL);

      // Doctrine has a mandatory service as first argument
      $argPos = ('propel' === $typeConfig['driver']) ? 0 : 1;

      $args[$argPos] = $typeConfig['model'];
      $args[$argPos + 1] = array(
        'hydrate'        => $typeConfig['elastica_to_model_transformer']['hydrate'],
        'identifier'     => $typeConfig['identifier'],
        'ignore_missing' => $typeConfig['elastica_to_model_transformer']['ignore_missing'],
        'query_builder_method' => $typeConfig['elastica_to_model_transformer']['query_builder_method']
      );

      return $app->make($abstractId, $args);
    });

    $this->app->extend('laravel-elastic-search.elastica_to_model_transformers', function ($tags) use ($serviceId, $typeName, $indexName) {
      $tags[$serviceId] = ['type' => $typeName, 'index' => $indexName];
      return $tags;
    });

    return $serviceId;
  }

  protected function loadModelToElasticaTransformer(array $typeConfig, $indexName, $typeName) {
    if (isset($typeConfig['model_to_elastica_transformer']['service'])) {
      return $typeConfig['model_to_elastica_transformer']['service'];
    }

    if ($this->serializerConfig) {
      $baseClass = 'FOS\ElasticaBundle\Transformer\ModelToElasticaIdentifierTransformer';
    } else {
      $baseClass = 'FOS\ElasticaBundle\Transformer\ModelToElasticaAutoTransformer';
    }

    $serviceId = sprintf('laravel-elastic-search.model_to_elastica_transformer.%s.%s', $indexName, $typeName);

    $this->app->singleton($serviceId, function ($app) use ($baseClass, $typeConfig) {
      $trans = new $baseClass(array(
        'identifier' => $typeConfig['identifier']
      ));
      $trans->setPropertyAccessor($app['laravel-elastic-search.property_accessor']);
      return $trans;
    });

    return $serviceId;
  }

  protected function loadObjectPersister(array $typeConfig, $typeId, $indexName, $typeName, $transformerId) {
    $arguments = array(
      $this->app[$typeId],
      $this->app[$transformerId],
      $typeConfig['model'],
    );

    if ($this->serializerConfig) {
      $baseClass = 'FOS\ElasticaBundle\Persister\ObjectSerializerPersister';
      $callbackId = sprintf('%s.%s.serializer.callback', $this->indexConfigs[$indexName]['index'], $typeName);
      $arguments[] = array($this->app[$callbackId], 'serialize');
    } else {
      $baseClass = 'FOS\ElasticaBundle\Persister\ObjectPersister';
      $arguments[] = $this->typeFields[sprintf('%s/%s', $indexName, $typeName)];
    }

    $serviceId = sprintf('laravel-elastic-search.object_persister.%s.%s', $indexName, $typeName);

    $this->app->singleton($serviceId, function ($app) use ($baseClass, $arguments) {
      $reflector = new \ReflectionClass($baseClass);
      return $reflector->newInstanceArgs($arguments);
    });

    return $serviceId;
  }

  protected function loadTypeProvider(array $typeConfig, $objectPersisterId, $indexName, $typeName) {
    if (isset($typeConfig['provider']['service'])) {
      return $typeConfig['provider']['service'];
    }
    /* Note: provider services may conflict with "prototype.driver", if the
     * index and type names were "prototype" and a driver, respectively.
     */
    $providerId = sprintf('laravel-elastic-search.provider.%s.%s', $indexName, $typeName);

    $this->app->singleton($providerId, function ($app) use ($typeConfig, $objectPersisterId) {
      $abstractId = sprintf('laravel-elastic-search.provider.prototype.%s', $typeConfig['driver']);
      $args = array(
        $app[$objectPersisterId],
        $typeConfig['model'],
        // Propel provider can simply ignore Doctrine-specific options
        array_diff_key($typeConfig['provider'], array('service' => 1))
      );
      return $app->make($abstractId, $args);
    });

    $this->app->extend('laravel-elastic-search.providers', function ($tags) use ($providerId, $indexName, $typeName) {
      $tags[$providerId] = array('index' => $indexName, 'type' => $typeName);
      return $tags;
    });

    return $providerId;
  }

  protected function loadTypeListener(array $typeConfig, $objectPersisterId, $indexName, $typeName) {
    if (isset($typeConfig['listener']['service'])) {
      return $typeConfig['listener']['service'];
    }

    $events = $this->getDoctrineEvents($typeConfig);
    $args = array($this->app[$objectPersisterId], $typeConfig['model'], $events, $typeConfig['identifier']);

    /* Note: listener services may conflict with "prototype.driver", if the
     * index and type names were "prototype" and a driver, respectively.
     */
    $abstractId = sprintf('laravel-elastic-search.listener.prototype.%s', $typeConfig['driver']);
    $listenerId = sprintf('laravel-elastic-search.listener.%s.%s', $indexName, $typeName);

    $this->app->singleton($listenerId, function ($app) use ($abstractId, $args) {
      $listener = $app->make($abstractId, $args);

      if (isset($typeConfig['listener']['is_indexable_callback'])) {
        $callback = $typeConfig['listener']['is_indexable_callback'];

        if (is_array($callback)) {
          list($class) = $callback + array(null);
          if (is_string($class) && !class_exists($class)) {
            $callback[0] = $app[$class];
          }
        }

        $listener->setIsIndexableCallback($callback);
      }

      return $listener;
    });

    // TODO: This might not work for MongoDB ODM.
    $this->app->extend('doctrine.event_subscribers', function ($tags) use ($listenerId) {
      $tags[] = $listenerId;
      return $tags;
    });

    return $listenerId;
  }

  private function getDoctrineEvents(array $typeConfig) {
    $events = array();
    $eventMapping = array(
      'insert' => array('postPersist'),
      'update' => array('postUpdate'),
      'delete' => array('postRemove', 'preRemove')
    );

    foreach ($eventMapping as $event => $doctrineEvents) {
      if (isset($typeConfig['listener'][$event]) && $typeConfig['listener'][$event]) {
        $events = array_merge($events, $doctrineEvents);
      }
    }

    return $events;
  }

  protected function loadTypeFinder(array $typeConfig, $elasticaToModelId, $typeId, $indexName, $typeName) {
    if (isset($typeConfig['finder']['service'])) {
      $finderId = $typeConfig['finder']['service'];
    } else {
      $finderId = sprintf('laravel-elastic-search.finder.%s.%s', $indexName, $typeName);

      $this->app->singleton($finderId, function ($app) use ($typeId, $elasticaToModelId) {
        return new TransformedFinder($app[$typeId], $app[$elasticaToModelId]);
      });
    }

    $managerId = sprintf('laravel-elastic-search.manager.%s', $typeConfig['driver']);

    $arguments = array($typeConfig['model'], $this->app[$finderId]);
    if (isset($typeConfig['repository'])) {
      $arguments[] = $typeConfig['repository'];
    }
    call_user_func_array(array($this->app[$managerId], 'addEntity'), $arguments);

    return $finderId;
  }

  /**
   * Loads the index manager
   *
   * @param array            $indexRefsByName
   * @param ContainerBuilder $container
   **/
  protected function registerIndexManager(array $indexRefsByName) {
    $this->app->singleton('laravel-elastic-search.index_manager', function ($app) use ($indexRefsByName) {
      return new IndexManager($indexRefsByName, $app['laravel-elastic-search.index']);
    });
  }

  /**
   * Loads the resetter
   *
   * @param array $indexConfigs
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
   */
  protected function registerResetter(array $indexConfigs) {
    $this->app->singleton('laravel-elastic-search.resetter', function ($app) use ($indexConfigs) {
      return new Resetter($indexConfigs);
    });
  }

  protected function createDefaultManagerAlias($defaultManager) {
    $drivers = $this->app['laravel-elastic-search.drivers'];
    if (0 == count($drivers)) {
      return;
    }

    if (count($drivers) > 1
      && in_array($defaultManager, $drivers)
    ) {
      $defaultManagerService = $defaultManager;
    } else {
      $defaultManagerService = $drivers[0];
    }

    $this->app['laravel-elastic-search.manager'] = $this->app[sprintf('laravel-elastic-search.manager.%s', $defaultManagerService)];
  }

  /**
   * Get the services provided by the provider.
   *
   * @return array
   */
  public function provides() {
    return array();
  }

}
