<?php

namespace Illuminate\View;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as FactoryContract;
use Illuminate\Contracts\View\View as ViewContract;

class Factory implements FactoryContract
{
    /**
     * The engine implementation.
     *
     * @var \Illuminate\View\Engines\EngineResolver
     */
    protected $engines;

    /**
     * The view finder implementation.
     *
     * @var \Illuminate\View\ViewFinderInterface
     */
    protected $finder;

    /**
     * The event dispatcher instance.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * The IoC container instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * Data that should be available to all templates.
     *
     * @var array
     */
    protected $shared = array();

    /**
     * The extension to engine bindings.
     *
     * @var array
     */
    protected $extensions = array(
        'blade.php' => 'blade',
        'php' => 'php',
        'css' => 'file',
    );

    /**
     * The view composer events.
     *
     * @var array
     */
    protected $composers = array();

    /**
     * The number of active rendering operations.
     *
     * @var int
     */
    protected $renderCount = 0;

    /**
     * Create a new view factory instance.
     *
     * @param  \Illuminate\View\Engines\EngineResolver  $engines
     * @param  \Illuminate\View\ViewFinderInterface  $finder
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function __construct(EngineResolver $engines, ViewFinderInterface $finder, Dispatcher $events)
    {
        $this->finder = $finder;
        $this->events = $events;
        $this->engines = $engines;

        $this->share('__env', $this);
    }

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param  string  $path
     * @param  array   $data
     * @param  array   $mergeData
     * @return \Illuminate\Contracts\View\View
     */
    public function file($path, $data = array(), $mergeData = array())
    {
        $data = array_merge($mergeData, $this->parseData($data));
        $thisVar = $this;
        return tap($this->viewInstance($path, $path, $data), function ($view) use ($thisVar){
            $thisVar->callCreator($view);
        });
    }

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param  string  $view
     * @param  array   $data
     * @param  array   $mergeData
     * @return \Illuminate\Contracts\View\View
     */
    public function make($view, $data = array(), $mergeData = array())
    {
        $path = $this->finder->find(
            $view = $this->normalizeName($view)
        );

        // Next, we will create the view instance and call the view creator for the view
        // which can set any data, etc. Then we will return the view instance back to
        // the caller for rendering or performing other view manipulations on this.
        $data = array_merge($mergeData, $this->parseData($data));
        $thisVar = $this;
        return tap($this->viewInstance($view, $path, $data), function ($view) use ($thisVar) {
            $thisVar->callCreator($view);
        });
    }

    /**
     * Get the rendered content of the view based on a given condition.
     *
     * @param  bool  $condition
     * @param  string  $view
     * @param  array   $data
     * @param  array   $mergeData
     * @return string
     */
    public function renderWhen($condition, $view, $data = array(), $mergeData = array())
    {
        if (! $condition) {
            return '';
        }

        return $this->make($view, $this->parseData($data), $mergeData)->render();
    }

    /**
     * Get the rendered contents of a partial from a loop.
     *
     * @param  string  $view
     * @param  array   $data
     * @param  string  $iterator
     * @param  string  $empty
     * @return string
     */
    public function renderEach($view, $data, $iterator, $empty = 'raw|')
    {
        $result = '';

        // If is actually data in the array, we will loop through the data and append
        // an instance of the partial view to the final result HTML passing in the
        // iterated value of this data array, allowing the views to access them.
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $result .= $this->make(
                    $view, array('key' => $key, $iterator => $value)
                )->render();
            }
        }

        // If there is no data in the array, we will render the contents of the empty
        // view. Alternatively, the "empty view" could be a raw string that begins
        // with "raw|" for convenience and to let this know that it is a string.
        else {
            $result = Str::startsWith($empty, 'raw|')
                        ? substr($empty, 4)
                        : $this->make($empty)->render();
        }

        return $result;
    }

    /**
     * Normalize a view name.
     *
     * @param  string $name
     * @return string
     */
    protected function normalizeName($name)
    {
        return ViewName::normalize($name);
    }

    /**
     * Parse the given data into a raw array.
     *
     * @param  mixed  $data
     * @return array
     */
    protected function parseData($data)
    {
        return $data instanceof Arrayable ? $data->toArray() : $data;
    }

    /**
     * Create a new view instance from the given arguments.
     *
     * @param  string  $view
     * @param  string  $path
     * @param  array  $data
     * @return \Illuminate\Contracts\View\View
     */
    protected function viewInstance($view, $path, $data)
    {
        return new View($this, $this->getEngineFromPath($path), $view, $path, $data);
    }

    /**
     * Determine if a given view exists.
     *
     * @param  string  $view
     * @return bool
     */
    public function exists($view)
    {
        try {
            $this->finder->find($view);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    /**
     * Get the appropriate view engine for the given path.
     *
     * @param  string  $path
     * @return \Illuminate\View\Engines\EngineInterface
     *
     * @throws \InvalidArgumentException
     */
    public function getEngineFromPath($path)
    {
        if (! $extension = $this->getExtension($path)) {
            throw new InvalidArgumentException("Unrecognized extension in file: $path");
        }

        $engine = $this->extensions[$extension];

        return $this->engines->resolve($engine);
    }

    /**
     * Get the extension used by the view file.
     *
     * @param  string  $path
     * @return string
     */
    protected function getExtension($path)
    {
        $extensions = array_keys($this->extensions);

        return Arr::first($extensions, function ($value) use ($path) {
            return Str::endsWith($path, '.'.$value);
        });
    }

    /**
     * Add a piece of shared data to the environment.
     *
     * @param  array|string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function share($key, $value = null)
    {
        $keys = is_array($key) ? $key : array($key => $value);

        foreach ($keys as $key => $value) {
            $this->shared[$key] = $value;
        }

        return $value;
    }

    /**
     * Increment the rendering counter.
     *
     * @return void
     */
    public function incrementRender()
    {
        $this->renderCount++;
    }

    /**
     * Decrement the rendering counter.
     *
     * @return void
     */
    public function decrementRender()
    {
        $this->renderCount--;
    }

    /**
     * Check if there are no active render operations.
     *
     * @return bool
     */
    public function doneRendering()
    {
        return $this->renderCount == 0;
    }

    /**
     * Add a location to the array of view locations.
     *
     * @param  string  $location
     * @return void
     */
    public function addLocation($location)
    {
        $this->finder->addLocation($location);
    }

    /**
     * Add a new namespace to the loader.
     *
     * @param  string  $namespace
     * @param  string|array  $hints
     * @return $this
     */
    public function addNamespace($namespace, $hints)
    {
        $this->finder->addNamespace($namespace, $hints);

        return $this;
    }

    /**
     * Prepend a new namespace to the loader.
     *
     * @param  string  $namespace
     * @param  string|array  $hints
     * @return $this
     */
    public function prependNamespace($namespace, $hints)
    {
        $this->finder->prependNamespace($namespace, $hints);

        return $this;
    }

    /**
     * Replace the namespace hints for the given namespace.
     *
     * @param  string  $namespace
     * @param  string|array  $hints
     * @return $this
     */
    public function replaceNamespace($namespace, $hints)
    {
        $this->finder->replaceNamespace($namespace, $hints);

        return $this;
    }

    /**
     * Register a valid view extension and its engine.
     *
     * @param  string    $extension
     * @param  string    $engine
     * @param  \Closure  $resolver
     * @return void
     */
    public function addExtension($extension, $engine, $resolver = null)
    {
        $this->finder->addExtension($extension);

        if (isset($resolver)) {
            $this->engines->register($engine, $resolver);
        }

        unset($this->extensions[$extension]);

        $this->extensions = array_merge(array($extension => $engine), $this->extensions);
    }

    /**
     * Flush all of the factory state like sections and stacks.
     *
     * @return void
     */
    public function flushState()
    {
        $this->renderCount = 0;

        $this->flushSections();
        $this->flushStacks();
    }

    /**
     * Flush all of the section contents if done rendering.
     *
     * @return void
     */
    public function flushStateIfDoneRendering()
    {
        if ($this->doneRendering()) {
            $this->flushState();
        }
    }

    /**
     * Get the extension to engine bindings.
     *
     * @return array
     */
    public function getExtensions()
    {
        return $this->extensions;
    }

    /**
     * Get the engine resolver instance.
     *
     * @return \Illuminate\View\Engines\EngineResolver
     */
    public function getEngineResolver()
    {
        return $this->engines;
    }

    /**
     * Get the view finder instance.
     *
     * @return \Illuminate\View\ViewFinderInterface
     */
    public function getFinder()
    {
        return $this->finder;
    }

    /**
     * Set the view finder instance.
     *
     * @param  \Illuminate\View\ViewFinderInterface  $finder
     * @return void
     */
    public function setFinder(ViewFinderInterface $finder)
    {
        $this->finder = $finder;
    }

    /**
     * Flush the cache of views located by the finder.
     *
     * @return void
     */
    public function flushFinderCache()
    {
        $this->getFinder()->flush();
    }

    /**
     * Get the event dispatcher instance.
     *
     * @return \Illuminate\Contracts\Events\Dispatcher
     */
    public function getDispatcher()
    {
        return $this->events;
    }

    /**
     * Set the event dispatcher instance.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function setDispatcher(Dispatcher $events)
    {
        $this->events = $events;
    }

    /**
     * Get the IoC container instance.
     *
     * @return \Illuminate\Contracts\Container\Container
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * Set the IoC container instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Get an item from the shared data.
     *
     * @param  string  $key
     * @param  mixed   $default
     * @return mixed
     */
    public function shared($key, $default = null)
    {
        return Arr::get($this->shared, $key, $default);
    }

    /**
     * Get all of the shared data for the environment.
     *
     * @return array
     */
    public function getShared()
    {
        return $this->shared;
    }


    /**
     * All of the finished, captured push sections.
     *
     * @var array
     */
    protected $pushes = array();

    /**
     * All of the finished, captured prepend sections.
     *
     * @var array
     */
    protected $prepends = array();

    /**
     * The stack of in-progress push sections.
     *
     * @var array
     */
    protected $pushStack = array();

    /**
     * Start injecting content into a push section.
     *
     * @param  string  $section
     * @param  string  $content
     * @return void
     */
    public function startPush($section, $content = '')
    {
        if ($content === '') {
            if (ob_start()) {
                $this->pushStack[] = $section;
            }
        } else {
            $this->extendPush($section, $content);
        }
    }
    
    /**
     * Append content to a given push section.
     *
     * @param  string  $section
     * @param  string  $content
     * @return void
     */
    protected function extendPush($section, $content)
    {
        if (! isset($this->pushes[$section])) {
            $this->pushes[$section] = array();
        }

        if (! isset($this->pushes[$section][$this->renderCount])) {
            $this->pushes[$section][$this->renderCount] = $content;
        } else {
            $this->pushes[$section][$this->renderCount] .= $content;
        }
    }

    /**
     * Start prepending content into a push section.
     *
     * @param  string  $section
     * @param  string  $content
     * @return void
     */
    public function startPrepend($section, $content = '')
    {
        if ($content === '') {
            if (ob_start()) {
                $this->pushStack[] = $section;
            }
        } else {
            $this->extendPrepend($section, $content);
        }
    }

    /**
     * Prepend content to a given stack.
     *
     * @param  string  $section
     * @param  string  $content
     * @return void
     */
    protected function extendPrepend($section, $content)
    {
        if (! isset($this->prepends[$section])) {
            $this->prepends[$section] = array();
        }

        if (! isset($this->prepends[$section][$this->renderCount])) {
            $this->prepends[$section][$this->renderCount] = $content;
        } else {
            $this->prepends[$section][$this->renderCount] = $content.$this->prepends[$section][$this->renderCount];
        }
    }

    /**
     * Get the string contents of a push section.
     *
     * @param  string  $section
     * @param  string  $default
     * @return string
     */
    public function yieldPushContent($section, $default = '')
    {
        if (! isset($this->pushes[$section]) && ! isset($this->prepends[$section])) {
            return $default;
        }

        $output = '';

        if (isset($this->prepends[$section])) {
            $output .= implode(array_reverse($this->prepends[$section]));
        }

        if (isset($this->pushes[$section])) {
            $output .= implode($this->pushes[$section]);
        }

        return $output;
    }

    /**
     * Flush all of the stacks.
     *
     * @return void
     */
    public function flushStacks()
    {
        $this->pushes = array();
        $this->prepends = array();
        $this->pushStack = array();
    }

/**
     * All of the finished, captured sections.
     *
     * @var array
     */
    protected $sections = array();

    /**
     * The stack of in-progress sections.
     *
     * @var array
     */
    protected $sectionStack = array();

    /**
     * The parent placeholder for the request.
     *
     * @var string
     */
    protected static $parentPlaceholder = array();

    /**
     * Start injecting content into a section.
     *
     * @param  string  $section
     * @param  string|null  $content
     * @return void
     */
    public function startSection($section, $content = null)
    {
        if ($content === null) {
            if (ob_start()) {
                $this->sectionStack[] = $section;
            }
        } else {
            $this->extendSection($section, e($content));
        }
    }

    /**
     * Inject inline content into a section.
     *
     * @param  string  $section
     * @param  string  $content
     * @return void
     */
    public function inject($section, $content)
    {
        return $this->startSection($section, $content);
    }

    /**
     * Stop injecting content into a section and return its contents.
     *
     * @return string
     */
    public function yieldSection()
    {
        if (empty($this->sectionStack)) {
            return '';
        }

        return $this->yieldContent($this->stopSection());
    }

    /**
     * Stop injecting content into a section.
     *
     * @param  bool  $overwrite
     * @return string
     * @throws \InvalidArgumentException
     */
    public function stopSection($overwrite = false)
    {
        if (empty($this->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->sectionStack);

        if ($overwrite) {
            $this->sections[$last] = ob_get_clean();
        } else {
            $this->extendSection($last, ob_get_clean());
        }

        return $last;
    }

    /**
     * Stop injecting content into a section and append it.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function appendSection()
    {
        if (empty($this->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->sectionStack);

        if (isset($this->sections[$last])) {
            $this->sections[$last] .= ob_get_clean();
        } else {
            $this->sections[$last] = ob_get_clean();
        }

        return $last;
    }

    /**
     * Append content to a given section.
     *
     * @param  string  $section
     * @param  string  $content
     * @return void
     */
    protected function extendSection($section, $content)
    {
        if (isset($this->sections[$section])) {
            $content = str_replace(static::parentPlaceholder($section), $content, $this->sections[$section]);
        }

        $this->sections[$section] = $content;
    }

    /**
     * Get the string contents of a section.
     *
     * @param  string  $section
     * @param  string  $default
     * @return string
     */
    public function yieldContent($section, $default = '')
    {
        $sectionContent = $default;

        if (isset($this->sections[$section])) {
            $sectionContent = $this->sections[$section];
        }

        $sectionContent = str_replace('@@parent', '--parent--holder--', $sectionContent);

        return str_replace(
            '--parent--holder--', '@parent', str_replace(static::parentPlaceholder($section), '', $sectionContent)
        );
    }

    /**
     * Get the parent placeholder for the current request.
     *
     * @param  string  $section
     * @return string
     */
    public static function parentPlaceholder($section = '')
    {
        if (! isset(static::$parentPlaceholder[$section])) {
            static::$parentPlaceholder[$section] = '##parent-placeholder-'.sha1($section).'##';
        }

        return static::$parentPlaceholder[$section];
    }

    /**
     * Check if section exists.
     *
     * @param  string  $name
     * @return bool
     */
    public function hasSection($name)
    {
        return array_key_exists($name, $this->sections);
    }

    /**
     * Get the entire array of sections.
     *
     * @return array
     */
    public function getSections()
    {
        return $this->sections;
    }

    /**
     * Flush all of the sections.
     *
     * @return void
     */
    public function flushSections()
    {
        $this->sections = array();
        $this->sectionStack = array();
    }

    /**
     * Register a view creator event.
     *
     * @param  array|string     $views
     * @param  \Closure|string  $callback
     * @return array
     */
    public function creator($views, $callback)
    {
        $creators = array();

        foreach ((array) $views as $view) {
            $creators[] = $this->addViewEvent($view, $callback, 'creating: ');
        }

        return $creators;
    }

    /**
     * Register multiple view composers via an array.
     *
     * @param  array  $composers
     * @return array
     */
    public function composers(array $composers)
    {
        $registered = array();

        foreach ($composers as $callback => $views) {
            $registered = array_merge($registered, $this->composer($views, $callback));
        }

        return $registered;
    }

    /**
     * Register a view composer event.
     *
     * @param  array|string  $views
     * @param  \Closure|string  $callback
     * @return array
     */
    public function composer($views, $callback)
    {
        $composers = array();

        foreach ((array) $views as $view) {
            $composers[] = $this->addViewEvent($view, $callback, 'composing: ');
        }

        return $composers;
    }

    /**
     * Add an event for a given view.
     *
     * @param  string  $view
     * @param  \Closure|string  $callback
     * @param  string  $prefix
     * @return \Closure|null
     */
    protected function addViewEvent($view, $callback, $prefix = 'composing: ')
    {
        $view = $this->normalizeName($view);

        if ($callback instanceof Closure) {
            $this->addEventListener($prefix.$view, $callback);

            return $callback;
        } elseif (is_string($callback)) {
            return $this->addClassEvent($view, $callback, $prefix);
        }
    }

    /**
     * Register a class based view composer.
     *
     * @param  string    $view
     * @param  string    $class
     * @param  string    $prefix
     * @return \Closure
     */
    protected function addClassEvent($view, $class, $prefix)
    {
        $name = $prefix.$view;

        // When registering a class based view "composer", we will simply resolve the
        // classes from the application IoC container then call the compose method
        // on the instance. This allows for convenient, testable view composers.
        $callback = $this->buildClassEventCallback(
            $class, $prefix
        );

        $this->addEventListener($name, $callback);

        return $callback;
    }

    /**
     * Parse a class based composer name.
     *
     * @param  string  $class
     * @param  string  $prefix
     * @return array
     */
    protected function parseClassEvent($class, $prefix)
    {
        return Str::parseCallback($class, $this->classEventMethodForPrefix($prefix));
    }

    /**
     * Determine the class event method based on the given prefix.
     *
     * @param  string  $prefix
     * @return string
     */
    protected function classEventMethodForPrefix($prefix)
    {
        return Str::contains($prefix, 'composing') ? 'compose' : 'create';
    }

    /**
     * Add a listener to the event dispatcher.
     *
     * @param  string    $name
     * @param  \Closure  $callback
     * @return void
     */
    protected function addEventListener($name, $callback)
    {
        if (Str::contains($name, '*')) {
            $callback = function ($name, array $data) use ($callback) {
                return $callback($data[0]);
            };
        }

        $this->events->listen($name, $callback);
    }

    /**
     * Call the composer for a given view.
     *
     * @param  \Illuminate\Contracts\View\View  $view
     * @return void
     */
    public function callComposer(ViewContract $view)
    {
        $this->events->fire('composing: '.$view->name(), array($view));
    }

    /**
     * Call the creator for a given view.
     *
     * @param  \Illuminate\Contracts\View\View  $view
     * @return void
     */
    public function callCreator(ViewContract $view)
    {
        $this->events->fire('creating: '.$view->name(), array($view));
    }

     /**
     * The components being rendered.
     *
     * @var array
     */
    protected $componentStack = array();

    /**
     * The original data passed to the component.
     *
     * @var array
     */
    protected $componentData = array();

    /**
     * The slot contents for the component.
     *
     * @var array
     */
    protected $slots = array();

    /**
     * The names of the slots being rendered.
     *
     * @var array
     */
    protected $slotStack = array();

    /**
     * Start a component rendering process.
     *
     * @param  string  $name
     * @param  array  $data
     * @return void
     */
    public function startComponent($name, array $data = array())
    {
        if (ob_start()) {
            $this->componentStack[] = $name;

            $this->componentData[$this->currentComponent()] = $data;

            $this->slots[$this->currentComponent()] = array();
        }
    }

    /**
     * Render the current component.
     *
     * @return string
     */
    public function renderComponent()
    {
        $name = array_pop($this->componentStack);

        return $this->make($name, $this->componentData($name))->render();
    }

    /**
     * Get the data for the given component.
     *
     * @param  string  $name
     * @return array
     */
    protected function componentData($name)
    {
        return array_merge(
            $this->componentData[count($this->componentStack)],
            array('slot' => new HtmlString(trim(ob_get_clean()))),
            $this->slots[count($this->componentStack)]
        );
    }

    /**
     * Start the slot rendering process.
     *
     * @param  string  $name
     * @param  string|null  $content
     * @return void
     */
    public function slot($name, $content = null)
    {
        if (count(func_get_args()) == 2) {
            $this->slots[$this->currentComponent()][$name] = $content;
        } else {
            if (ob_start()) {
                $this->slots[$this->currentComponent()][$name] = '';

                $this->slotStack[$this->currentComponent()][] = $name;
            }
        }
    }

    /**
     * Save the slot content for rendering.
     *
     * @return void
     */
    public function endSlot()
    {
        last($this->componentStack);

        $currentSlot = array_pop(
            $this->slotStack[$this->currentComponent()]
        );

        $this->slots[$this->currentComponent()]
                    [$currentSlot] = new HtmlString(trim(ob_get_clean()));
    }

    /**
     * Get the index for the current component.
     *
     * @return int
     */
    protected function currentComponent()
    {
        return count($this->componentStack) - 1;
    }
}