<?php

return [
  'clients' => [
    'default' => [ 'host' => 'localhost', 'port' => '9200' ],
  ],
  // 'serializer' => NULL,
  'serializer' => [
    'callback_class' => 'FOS\ElasticaBundle\Serializer\Callback',
    'serializer' => 'serializer',
  ],
  'indexes' => [
    'EXAMPLE_INDEX' => [
      'client' => 'default',
      'finder' => TRUE,
      'types' => [
        'EXAMPLE_TYPE' => [
          'mappings' => [
            'title' => [ 'type' => 'string' ],
          ],
          'persistence' => [
            'driver' => 'orm', // orm, mongodb, propel are available
            'model' => 'MODELS\EXAMPLEMODEL',
            'identifier' => 'id',
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
      // 'settings' => [
      //   'index' => [
      //     'analysis' => [
      //       'analyzer' => [
      //         'my_analyzer' => [
      //           'type' => 'snowball',
      //           'language' => 'English',
      //         ],
      //       ],
      //     ],
      //   ],
      // ],
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
  'default_manager' => 'orm',
  'default_client' => NULL,
  'default_index' => NULL,
];
