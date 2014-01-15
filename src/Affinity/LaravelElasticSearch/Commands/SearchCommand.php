<?php namespace Affinity\LaravelElasticSearch\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

use Elastica\Query;
use Elastica\Result;

class SearchCommand extends Command {

  /**
   * The console command name.
   *
   * @var string
   */
  protected $name = 'elastic-search:search';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Searches Elastic Search for documents in a given type and index';

  /**
   * @var IndexManager
   */
  private $indexManager;

  /**
   * @var Resetter
   */
  private $resetter;

  /**
   * Create a new command instance.
   *
   * @return void
   */
  public function __construct() {
    parent::__construct();
    $app = app();
    $this->indexManager = $app['laravel-elastic-search.index_manager'];
    $this->resetter = $app['laravel-elastic-search.resetter'];
  }

  /**
   * Execute the console command.
   *
   * @return void
   */
  public function fire() {
    $app = app();
    $indexName = $this->option('index');
    /** @var $index \Elastica\Index */
    $index = $app['laravel-elastic-search.index_manager']->getIndex($indexName ? $indexName : null);
    $type  = $index->getType($this->argument('type'));
    $query = Query::create($this->argument('query'));
    $query->setSize($this->option('limit'));
    if ($this->option('explain')) {
      $query->setExplain(true);
    }

    $resultSet = $type->search($query);

    $this->output->writeLn(sprintf('Found %d results', $type->count($query)));
    foreach ($resultSet->getResults() as $result) {
      $this->output->writeLn($this->formatResult($result, $this->option('show-field'), $this->option('show-source'), $this->option('show-id'), $this->option('explain')));
    }
  }

  protected function formatResult(Result $result, $showField, $showSource, $showId, $explain) {
    $source = $result->getSource();
    if ($showField) {
      $toString = isset($source[$showField]) ? $source[$showField] : '-';
    } else {
      $toString = reset($source);
    }
    $string = sprintf('[%0.2f] %s', $result->getScore(), var_export($toString, true));
    if ($showSource) {
      $string = sprintf('%s %s', $string, json_encode($source, JSON_PRETTY_PRINT));
    }
    if ($showId) {
      $string = sprintf('{%s} %s', $result->getId(), $string);
    }
    if ($explain) {
      $string = sprintf('%s %s', $string, json_encode($result->getExplanation(), JSON_PRETTY_PRINT));
    }

    return $string;
  }

  /**
   * Get the console command arguments.
   *
   * @return array
   */
  protected function getArguments() {
    return array(
      array('type', InputArgument::REQUIRED, 'The type to search in'),
      array('query', InputArgument::REQUIRED, 'The text to search'),
    );
  }

  /**
   * Get the console command options.
   *
   * @return array
   */
  protected function getOptions() {
    return array(
      array('index', null, InputOption::VALUE_REQUIRED, 'The index to search in'),
      array('limit', null, InputOption::VALUE_REQUIRED, 'The maximum number of documents to return', 20),
      array('show-field', null, InputOption::VALUE_REQUIRED, 'Field to show, null uses the first field'),
      array('show-source', null, InputOption::VALUE_NONE, 'Show the documents sources'),
      array('show-id', null, InputOption::VALUE_NONE, 'Show the documents ids'),
      array('explain', null, InputOption::VALUE_NONE, 'Enables explanation for each hit on how its score was computed.'),
    );
  }

}


