<?php

return [
  'default_manager' => 'orm',
  'default_client' => NULL,
  'default_index' => NULL,
  'clients' => [
    'default' => [ 'host' => 'localhost', 'port' => '9200' ],
  ],
  'serializer' => NULL,
  // 'serializer' => [
  //   'callback_class' => 'FOS\ElasticaBundle\Serializer\Callback',
  //   'serializer' => 'serializer',
  // ],
  'indexes' => [
    'matching' => [
      'settings' => [
        'index' => [
          'analysis' => [
            'analyzer' => [
              'analyzer_startswith' => [
                'type' => 'custom',
                'tokenizer' => 'keyword',
                'filter' => ['lowercase'],
                // 'type' => 'snowball',
                // 'language' => 'English',
              ],
            ],
          ],
        ],
      ],
      'client' => 'default',
      'finder' => TRUE,
      'types' => [
        'ntee' => [
          'mappings' => [
            'shortcode' => [ 'type' => 'string', 'analyzer' => 'analyzer_startswith' ],
          ],
          'persistence' => [
            'driver' => 'orm', # orm, mongodb, propel are available
            'model' => 'PCGCore\Models\Doctrine\Sector',
            'identifier' => 'shortcode',
            'provider' => [
              'query_builder_method' => 'createQueryBuilder',
              'batch_size' => 100,
              'clear_object_manager' => TRUE,
            ],
            'listener' => array(
              'insert' => TRUE,
              'update' => TRUE,
              'delete' => TRUE,
              'is_indexable_callback' => NULL,
            ),
            'elastica_to_model_transformer' => [
              'hydrate' => TRUE,
              'ignore_missing' => FALSE,
              'query_builder_method' => 'createQueryBuilder',
              'service' => NULL,
            ],
          ],
        ],
      ],
    ],
  ],
  'parameters' => [
    'ClientClass' => 'FOS\ElasticaBundle\Client',
    'IndexClass' => 'Elastica\Index',
    'TypeClass' => 'Elastica\Type',
    'LoggerClass' => 'FOS\ElasticaBundle\Logger\ElasticaLogger',
    'DataCollectorClass' => 'FOS\ElasticaBundle\DataCollector\ElasticaDataCollector',
    'ManagerClass' => 'FOS\ElasticaBundle\Manager\RepositoryManager',
    'ElasticaToModelTransformerCollectionClass' => 'FOS\ElasticaBundle\Transformer\ElasticaToModelTransformerCollection',
    'ProviderRegistryClass' => 'Affinity\LaravelElasticSearch\Provider\LaravelProviderRegistry',
    'PropertyAccessorClass' => 'Symfony\Component\PropertyAccess\PropertyAccessor',
  ],
];
