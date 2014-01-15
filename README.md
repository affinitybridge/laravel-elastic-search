laravel-elastic-search
======================

Laravel 4 (-ish) port of [FOSElasticaBundle].

Currently dependent on [AffinityBridge fork](https://github.com/affinitybridge/FOSElasticaBundle) of [FOSElasticaBundle] which removes dependency on [SymfonyFrameworkBundle].

Configuration and assembly which was handled by Symfony's DIC and Configuration components have been replaced with a (convoluted & messy) Laravel Service Provider and Laravel's array-based configuration.

[FOSElasticaBundle]: https://github.com/FriendsOfSymfony/FOSElasticaBundle "Friends of Symfony Elastica Bundle"
[SymfonyFrameworkBundle]: https://github.com/symfony/FrameworkBundle "Symfony Framework Bundle"
