<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * Simulates the Composer commands the updater runs.
 *
 * Current versions come from composer.lock, direct requirements from
 * composer.json, and available versions and advisories from the scenario
 * packages. Dependency resolution is not simulated: "update" locks the
 * requested packages (and, with -w/-W, their requirements) at their latest
 * version. Any other command fails as unsupported.
 */
final class ComposerSimulator extends ToolSimulator {

  /**
   * {@inheritdoc}
   */
  protected function simulate(Invocation $invocation): int {
    return match ($invocation->arguments()[0] ?? '') {
      'show', 'info' => $this->show($invocation),
      'why', 'depends' => $this->why($invocation),
      'audit' => $this->audit($invocation),
      'update', 'u', 'upgrade' => $this->update($invocation),
      default => $this->unsupported($invocation),
    };
  }

  /**
   * Simulates "show": package details, outdated lists and direct lists.
   */
  private function show(Invocation $invocation): int {
    $files = new ComposerFiles($invocation->cwd());
    $include_dev = !$invocation->hasOption('--no-dev');
    $positionals = $invocation->positionals(['--format', '-f', '--ignore']);
    if (count($positionals) === 1) {
      return $this->showPackage($invocation, $files, $positionals[0]);
    }
    if ($positionals !== []) {
      return $this->unsupported($invocation);
    }
    $only_direct = $invocation->hasOption('--direct', '-D');
    $name_only = $invocation->hasOption('--name-only', '-N');
    if ($invocation->hasOption('--outdated', '-o')) {
      $outdated = $this->outdated($invocation, $files, $include_dev, $only_direct);
      $format = $invocation->option('--format') ?? $invocation->option('-f') ?? 'text';
      $invocation->out(match (TRUE) {
        $name_only => ComposerOutput::names(array_column($outdated, 'name')),
        $format === 'json' => ComposerOutput::outdatedJson($outdated),
        default => ComposerOutput::outdatedText($outdated),
      });
      return 0;
    }
    if ($only_direct && $name_only) {
      $direct = array_keys($files->rootRequirements($include_dev));
      sort($direct);
      $invocation->out(ComposerOutput::names($direct));
      return 0;
    }
    return $this->unsupported($invocation);
  }

  /**
   * Simulates "show <package>".
   */
  private function showPackage(Invocation $invocation, ComposerFiles $files, string $name): int {
    $package = $files->lockedPackages()[$name] ?? NULL;
    if ($package === NULL) {
      $invocation->err(sprintf("Package \"%s\" not found, try using --available (-a) to show all available packages.\n", $name));
      return 1;
    }
    $invocation->out(ComposerOutput::packageDetails($package));
    return 0;
  }

  /**
   * Gets the locked packages with a newer version declared in the scenario.
   *
   * @return list<array{name: string, version: string, latest: string, direct: bool}>
   *   Outdated packages, sorted by name.
   */
  private function outdated(Invocation $invocation, ComposerFiles $files, bool $include_dev, bool $only_direct): array {
    $declared = $invocation->state()->packages();
    $direct = $files->rootRequirements($include_dev);
    $outdated = [];
    foreach ($files->lockedPackages($include_dev) as $name => $package) {
      $latest = isset($declared[$name]) ? $declared[$name]->latest() : NULL;
      $is_direct = isset($direct[$name]);
      if ($latest === NULL || $latest === $package['version'] || ($only_direct && !$is_direct)) {
        continue;
      }
      $outdated[] = ['name' => $name, 'version' => $package['version'], 'latest' => $latest, 'direct' => $is_direct];
    }
    return $outdated;
  }

  /**
   * Simulates "why <package>", recursive with -r.
   */
  private function why(Invocation $invocation): int {
    $files = new ComposerFiles($invocation->cwd());
    $target = $invocation->positionals()[0] ?? '';
    $locked = $files->lockedPackages();
    if (!isset($locked[$target])) {
      $invocation->err(sprintf("Could not find package \"%s\" in your project\n", $target));
      return 1;
    }
    $recursive = $invocation->hasOption('-r', '--recursive');
    $links = [];
    $pending = [$target];
    $seen = [];
    while ($pending !== []) {
      $current = array_shift($pending);
      foreach ($this->requirers($files, $locked, $current) as $link) {
        $links[] = $link;
        if ($recursive && isset($locked[$link['name']]) && !isset($seen[$link['name']])) {
          $seen[$link['name']] = TRUE;
          $pending[] = $link['name'];
        }
      }
    }
    if ($links === []) {
      $invocation->err(sprintf("There is no installed package depending on \"%s\"\n", $target));
      return 1;
    }
    // Composer lists the links closest to the root first.
    $invocation->out(ComposerOutput::why(array_reverse($links)));
    return 0;
  }

  /**
   * Gets the packages, including the root one, that require a package.
   *
   * @param \DrupalUpdater\Tests\Simulator\ComposerFiles $files
   *   Project files.
   * @param array<string, array{name: string, version: string, type: string, require: array<string, string>, dev: bool}> $locked
   *   Locked packages.
   * @param string $target
   *   Required package.
   *
   * @return list<array{name: string, version: string, target: string, constraint: string}>
   *   Requirement links.
   */
  private function requirers(ComposerFiles $files, array $locked, string $target): array {
    $links = [];
    $root = $files->rootRequirements();
    if (isset($root[$target])) {
      $links[] = [
        'name' => $files->rootName(),
        'version' => $files->rootVersion(),
        'target' => $target,
        'constraint' => $root[$target],
      ];
    }
    foreach ($locked as $package) {
      if (isset($package['require'][$target])) {
        $links[] = [
          'name' => $package['name'],
          'version' => $package['version'],
          'target' => $target,
          'constraint' => $package['require'][$target],
        ];
      }
    }
    return $links;
  }

  /**
   * Simulates "audit" from the advisories declared in the scenario.
   */
  private function audit(Invocation $invocation): int {
    $format = $invocation->option('--format') ?? $invocation->option('-f') ?? 'table';
    if ($format !== 'plain') {
      return $this->unsupported($invocation);
    }
    $files = new ComposerFiles($invocation->cwd());
    $declared = $invocation->state()->packages();
    $advisories = [];
    foreach ($files->lockedPackages(!$invocation->hasOption('--no-dev')) as $name => $package) {
      if (!isset($declared[$name])) {
        continue;
      }
      // An advisory affects the versions older than the declared latest.
      $latest = $declared[$name]->latest();
      if ($latest !== NULL && version_compare($package['version'], $latest, '>=')) {
        continue;
      }
      foreach ($declared[$name]->advisories() as $advisory) {
        $advisories[] = $advisory + ['package' => $name, 'affected' => $latest !== NULL ? '<' . $latest : '*'];
      }
    }
    $invocation->out(ComposerOutput::auditPlain($advisories));
    return $advisories === [] ? 0 : 1;
  }

  /**
   * Simulates "update": locks the requested packages at their latest version.
   */
  private function update(Invocation $invocation): int {
    $files = new ComposerFiles($invocation->cwd());
    $locked = $files->lockedPackages();
    $invocation->err("Loading composer repositories with package information\n");
    $names = [];
    foreach ($invocation->positionals() as $pattern) {
      // Package arguments accept "*" wildcards, e.g. drupal/core*.
      $regex = '{^' . str_replace('\\*', '.*', preg_quote($pattern)) . '$}i';
      $matches = array_filter(array_keys($locked), fn (string $name) => preg_match($regex, $name) === 1);
      if ($matches === []) {
        $invocation->err(sprintf("Package \"%s\" listed for update is not locked.\n", $pattern));
      }
      $names = array_merge($names, $matches);
    }
    if ($invocation->hasOption('--with-dependencies', '-w', '--with-all-dependencies', '-W')) {
      $names = $this->withRequirements($names, $locked);
    }
    $versions = $this->newVersions($invocation, $names, $locked);
    $invocation->err("Updating dependencies\n");
    if ($versions === []) {
      $invocation->err("Nothing to modify in lock file\n");
      return 0;
    }
    $invocation->err(sprintf("Lock file operations: 0 installs, %d updates, 0 removals\n", count($versions)));
    foreach ($versions as $name => $version) {
      $invocation->err(sprintf("  - Upgrading %s (%s => %s)\n", $name, $locked[$name]['version'], $version));
    }
    $files->setLockedVersions($versions);
    $invocation->err("Writing lock file\n");
    return 0;
  }

  /**
   * Adds the locked requirements of the packages, recursively.
   *
   * @param list<string> $names
   *   Package names.
   * @param array<string, array{name: string, version: string, type: string, require: array<string, string>, dev: bool}> $locked
   *   Locked packages.
   *
   * @return list<string>
   *   Package names with their requirements.
   */
  private function withRequirements(array $names, array $locked): array {
    $pending = $names;
    $all = [];
    while ($pending !== []) {
      $name = array_shift($pending);
      if (isset($all[$name]) || !isset($locked[$name])) {
        continue;
      }
      $all[$name] = TRUE;
      $pending = array_merge($pending, array_keys($locked[$name]['require']));
    }
    return array_keys($all);
  }

  /**
   * Gets the new version of the packages that have a newer one declared.
   *
   * @param \DrupalUpdater\Tests\Simulator\Invocation $invocation
   *   Invocation.
   * @param list<string> $names
   *   Package names.
   * @param array<string, array{name: string, version: string, type: string, require: array<string, string>, dev: bool}> $locked
   *   Locked packages.
   *
   * @return array<string, string>
   *   New versions keyed by package name.
   */
  private function newVersions(Invocation $invocation, array $names, array $locked): array {
    $declared = $invocation->state()->packages();
    $versions = [];
    foreach (array_unique($names) as $name) {
      $latest = isset($declared[$name]) ? $declared[$name]->latest() : NULL;
      if ($latest !== NULL && $latest !== $locked[$name]['version']) {
        $versions[$name] = $latest;
      }
    }
    return $versions;
  }

}
