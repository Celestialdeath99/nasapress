<?php

namespace Illuminate\Contracts\Auth\Access;

interface Gate
{
    /**
     * Determine if a given ability has been defined.
     *
     * @param  string  $ability
     * @return bool
     */
    public function has($ability);

    /**
     * Define a new ability.
     *
     * @param  string  $ability
     * @param  callable|string  $callback
     * @return $this
     */
    public function define($ability, $callback);

    /**
     * Define a policy class for a given class type.
     *
     * @param  string  $class
     * @param  string  $policy
     * @return $this
     */
    public function policy($class, $policy);

    /**
     * Determine if the given ability should be granted for the current user.
     *
     * @param  string  $ability
     * @param  array|mixed  $arguments
     * @return bool
     */
    public function allows($ability, $arguments = array());

    /**
     * Determine if the given ability should be denied for the current user.
     *
     * @param  string  $ability
     * @param  array|mixed  $arguments
     * @return bool
     */
    public function denies($ability, $arguments = array());

    /**
     * Determine if the given ability should be granted.
     *
     * @param  string  $ability
     * @param  array|mixed  $arguments
     * @return bool
     */
    public function check($ability, $arguments = array());

    /**
     * Determine if the given ability should be granted for the current user.
     *
     * @param  string  $ability
     * @param  array|mixed  $arguments
     * @return \Illuminate\Auth\Access\Response
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function authorize($ability, $arguments = array());

    /**
     * Get a policy instance for a given class.
     *
     * @param  object|string  $class
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function getPolicyFor($class);

    /**
     * Get a guard instance for the given user.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|mixed  $user
     * @return static
     */
    public function forUser($user);
}
