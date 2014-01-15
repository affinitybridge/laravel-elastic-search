<?php namespace Affinity\LaravelElasticSearch\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class ResetCommand extends Command {

  /**
   * The console command name.
   *
   * @var string
   */
  protected $name = 'elastic-search:reset';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Reset Elastic Search indexes.';

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
    $index         = $this->option('index');
    $type          = $this->option('type');

    if (null === $index && null !== $type) {
      throw new \InvalidArgumentException('Cannot specify type option without an index.');
    }

    if (null !== $type) {
      $this->output->writeln(sprintf('<info>Resetting</info> <comment>%s/%s</comment>', $index, $type));
      $this->resetter->resetIndexType($index, $type);
    } else {
      $indexes = null === $index
        ? array_keys($this->indexManager->getAllIndexes())
        : array($index);

      foreach ($indexes as $index) {
        $this->output->writeln(sprintf('<info>Resetting</info> <comment>%s</comment>', $index));
        $this->resetter->resetIndex($index);
      }
    }
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
      array('index', null, InputOption::VALUE_OPTIONAL, 'The index to reset'),
      array('type', null, InputOption::VALUE_OPTIONAL, 'The type to reset'),
    );
  }

}

