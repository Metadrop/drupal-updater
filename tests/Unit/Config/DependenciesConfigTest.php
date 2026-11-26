<?php

namespace DrupalUpdater\Tests\Unit\Config;

use DrupalUpdater\Config\Config;
use DrupalUpdater\Config\Dependencies;
use PHPUnit\Framework\TestCase;

/**
 * Tests the dependencies configuration key.
 */
final class DependenciesConfigTest extends TestCase {

  /**
   * Tests the default level and the configured ones.
   */
  public function testDefaultAndConfiguredLevels(): void {
    self::assertSame(Dependencies::Indirect, (new Config())->getDependencies());
    self::assertSame(Dependencies::Indirect, $this->configuration('')->getDependencies());
    self::assertSame(Dependencies::All, $this->configuration('dependencies: all')->getDependencies());
    self::assertSame(Dependencies::Indirect, $this->configuration('dependencies: indirect')->getDependencies());
    self::assertSame(Dependencies::None, $this->configuration('dependencies: none')->getDependencies());
  }

  /**
   * Tests invalid levels are rejected.
   */
  public function testInvalidLevelsAreRejected(): void {
    foreach (['null', 'true', '1', 'All', 'with-dependencies', '[all]'] as $value) {
      try {
        $this->configuration('dependencies: ' . $value);
        self::fail('Accepted invalid dependencies level: ' . $value);
      }
      catch (\InvalidArgumentException $e) {
        self::assertStringContainsString('dependencies', $e->getMessage());
      }
    }
  }

  /**
   * Tests the Composer option of each level.
   */
  public function testComposerOptions(): void {
    self::assertSame('--with-all-dependencies', Dependencies::All->composerOption());
    self::assertSame('--with-dependencies', Dependencies::Indirect->composerOption());
    self::assertSame('', Dependencies::None->composerOption());
  }

  /**
   * Loads a configuration from YAML contents.
   */
  private function configuration(string $yaml): Config {
    $file = tempnam(sys_get_temp_dir(), 'updater-dependencies-');
    try {
      file_put_contents($file, $yaml);
      return Config::createFromConfigurationFile($file);
    }
    finally {
      unlink($file);
    }
  }

}
