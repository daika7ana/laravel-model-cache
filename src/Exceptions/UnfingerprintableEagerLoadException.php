<?php

namespace Daika7ana\LaravelModelCache\Exceptions;

/**
 * Thrown internally when an eager-load constraint captures state that cannot be
 * fingerprinted from code identity alone. The caller catches this and attempts
 * to resolve the constraint against a fresh relation instead.
 *
 * @internal
 */
class UnfingerprintableEagerLoadException extends \RuntimeException {}
