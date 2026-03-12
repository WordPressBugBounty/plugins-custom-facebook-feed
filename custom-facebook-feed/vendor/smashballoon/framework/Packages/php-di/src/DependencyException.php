<?php


namespace FacebookFeed\Vendor\DI;

use FacebookFeed\Vendor\Psr\Container\ContainerExceptionInterface;
/**
 * Exception for the Container.
 */
class DependencyException extends \Exception implements ContainerExceptionInterface
{
}
