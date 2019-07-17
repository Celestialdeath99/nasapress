<?php

namespace Illuminate\Contracts\Validation;

use Illuminate\Contracts\Support\MessageProvider;

interface Validator extends MessageProvider
{
    /**
     * Determine if the data fails the validation rules.
     *
     * @return bool
     */
    public function fails();

    /**
     * Get the failed validation rules.
     *
     * @return array
     */
    public function failed();

    /**
     * After an after validation callback.
     *
     * @param  callable|string  $callback
     * @return $this
     */
    public function after($callback);
}
