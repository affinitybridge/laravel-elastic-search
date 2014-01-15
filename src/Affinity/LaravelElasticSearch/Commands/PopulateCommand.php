<?php namespace Affinity\LaravelElasticSearch\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class PopulateCommand extends Command {

  /**
   * The console command name.
   *
   * @var string
   */
  protected $name = 'elastic-search:populate';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Populates Elastic Search indexes from providers.';

  /**
   * @var IndexManager
   */
  private $indexManager;

  /**
   * @var ProviderRegistry
   */
  private $providerRegistry;

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
    $this->providerRegistry = $app['laravel-elastic-search.provider_registry'];
    $this->resetter = $app['laravel-elastic-search.resetter'];
  }

  /**
   * Execute the console command.
   *
   * @return void
   */
  public function fire() {
    $index         = $this->option('index');
    $type          = $this->option('type');
    $reset         = $this->option('no-reset') ? false : true;
    $noInteraction = $this->option('no-interaction');
    $options       = $this->option();

    if (!$noInteraction && $reset && $this->option('offset')) {
      if (!$this->confirm('<question>You chose to reset the index and start indexing with an offset. Do you really want to do that?</question>', TRUE)) {
        return;
      }
    }

    if (null === $index && null !== $type) {
      throw new \InvalidArgumentException('Cannot specify type option without an index.');
    }

    if (null !== $index) {
      if (null !== $type) {
        $this->populateIndexType($index, $type, $reset, $options);
      } else {
        $this->populateIndex($index, $reset, $options);
      }
    } else {
      $indexes = array_keys($this->indexManager->getAllIndexes());

      foreach ($indexes as $index) {
        $this->populateIndex($index, $reset, $options);
      }
    }
  }

  /**
   * Recreates an index, populates its types, and refreshes the index.
   *
   * @param string          $index
   * @param boolean         $reset
   * @param array           $options
   */
  private function populateIndex($index, $reset, $options) {
    $output = $this->output;

    if ($reset) {
      $output->writeln(sprintf('<info>Resetting</info> <comment>%s</comment>', $index));
      $this->resetter->resetIndex($index);
    }

    /** @var $providers ProviderInterface[] */
    $providers = $this->providerRegistry->getIndexProviders($index);

    foreach ($providers as $type => $provider) {
      $loggerClosure = function($message) use ($output, $index, $type) {
        $output->writeln(sprintf('<info>Populating</info> %s/%s, %s', $index, $type, $message));
      };

      $provider->populate($loggerClosure, $options);
    }

    $output->writeln(sprintf('<info>Refreshing</info> <comment>%s</comment>', $index));
    $this->indexManager->getIndex($index)->refresh();
  }

  /**
   * Deletes/remaps an index type, populates it, and refreshes the index.
   *
   * @param string          $index
   * @param string          $type
   * @param boolean         $reset
   * @param array           $options
   */
  private function populateIndexType($index, $type, $reset, $options) {
    $output = $this->output;

    if ($reset) {
      $output->writeln(sprintf('<info>Resetting</info> <comment>%s/%s</comment>', $index, $type));
      $this->resetter->resetIndexType($index, $type);
    }

    $loggerClosure = function($message) use ($output, $index, $type) {
      $output->writeln(sprintf('<info>Populating</info> %s/%s, %s', $index, $type, $message));
    };

    $provider = $this->providerRegistry->getProvider($index, $type);
    $provider->populate($loggerClosure, $options);

    $output->writeln(sprintf('<info>Refreshing</info> <comment>%s</comment>', $index));
    $this->indexManager->getIndex($index)->refresh();
  }

  /**
   * Get the console command arguments.
   *
   * @return array
   */
  protected function getArguments() {
    return array();
  }

  /**
   * Get the console command options.
   *
   * @return array
   */
  protected function getOptions() {
    return array(
      array('index', null, InputOption::VALUE_OPTIONAL, 'The index to repopulate'),
      array('type', null, InputOption::VALUE_OPTIONAL, 'The type to repopulate'),
      array('no-reset', null, InputOption::VALUE_NONE, 'Do not reset index before populating'),
      array('offset', null, InputOption::VALUE_REQUIRED, 'Start indexing at offset', 0),
      array('sleep', null, InputOption::VALUE_REQUIRED, 'Sleep time between persisting iterations (microseconds)', 0),
      array('batch-size', null, InputOption::VALUE_REQUIRED, 'Index packet size (overrides provider config option)'),
    );
  }

}
