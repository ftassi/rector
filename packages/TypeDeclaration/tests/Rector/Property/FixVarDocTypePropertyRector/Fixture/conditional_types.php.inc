<?php declare(strict_types=1);

namespace Rector\Testing\PHPUnit;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;

final class ConditionalTypes
{
    /**
     * @var Container|ContainerInterface|null
     */
    private static $allRectorContainer;

    /**
     * @var ContainerInterface
     */
    private static $container;

    public function hi(): void
    {
        // defined in phpunit.xml
        if (defined('RECTOR_REPOSITORY')) {
            if (self::$allRectorContainer === null) {
                self::$allRectorContainer = self::$container;
            }
        }
    }
}

?>
-----
<?php declare(strict_types=1);

namespace Rector\Testing\PHPUnit;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;

final class ConditionalTypes
{
    /**
     * @var \Psr\Container\ContainerInterface|null
     */
    private static $allRectorContainer;

    /**
     * @var ContainerInterface
     */
    private static $container;

    public function hi(): void
    {
        // defined in phpunit.xml
        if (defined('RECTOR_REPOSITORY')) {
            if (self::$allRectorContainer === null) {
                self::$allRectorContainer = self::$container;
            }
        }
    }
}

?>
