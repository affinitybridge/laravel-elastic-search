<?php namespace Affinity\LaravelElasticSearch\Provider;

/**
 * References persistence providers for each index and type.
 */
class LaravelProviderRegistry {

    /**
     * @var
     */
    private $container;
    private $providers = array();

    /**
     * Registers a provider for the specified index and type.
     *
     * @param string $index
     * @param string $type
     * @param string $providerId
     */
    public function addProvider($index, $type, $providerId)
    {
        if (!isset($this->providers[$index])) {
            $this->providers[$index] = array();
        }

        $this->providers[$index][$type] = $providerId;
    }

    /**
     * Gets all registered providers.
     *
     * Providers will be indexed by "index/type" strings in the returned array.
     *
     * @return array of ProviderInterface instances
     */
    public function getAllProviders()
    {
        $providers = array();

        foreach ($this->providers as $index => $indexProviders) {
            foreach ($indexProviders as $type => $providerId) {
                $providers[sprintf('%s/%s', $index, $type)] = App::make($providerId);
            }
        }

        return $providers;
    }

    /**
     * Gets all providers for an index.
     *
     * Providers will be indexed by "type" strings in the returned array.
     *
     * @param string  $index
     * @return array of ProviderInterface instances
     * @throws \InvalidArgumentException if no providers were registered for the index
     */
    public function getIndexProviders($index)
    {
        if (!isset($this->providers[$index])) {
            throw new \InvalidArgumentException(sprintf('No providers were registered for index "%s".', $index));
        }

        $providers = array();

        foreach ($this->providers[$index] as $type => $providerId) {
            $providers[$type] = \App::make($providerId);
        }

        return $providers;
    }

    /**
     * Gets the provider for an index and type.
     *
     * @param string $index
     * @param string $type
     * @return ProviderInterface
     * @throws \InvalidArgumentException if no provider was registered for the index and type
     */
    public function getProvider($index, $type)
    {
        if (!isset($this->providers[$index][$type])) {
            throw new \InvalidArgumentException(sprintf('No provider was registered for index "%s" and type "%s".', $index, $type));
        }

        return \App::make($this->providers[$index][$type]);
    }
}

