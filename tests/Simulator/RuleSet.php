<?php

namespace DrupalUpdater\Tests\Simulator;

/**
 * The scripted rules of one tool, in the order they are tried.
 */
final class RuleSet {

  /**
   * Rules; the first matching one answers the command.
   *
   * @var list<\DrupalUpdater\Tests\Simulator\Rule>
   */
  private array $rules = [];

  /**
   * Whether commands no rule matches go to the real tool.
   */
  private bool $realByDefault = FALSE;

  /**
   * Restores a rule set from its array form.
   *
   * @param array<string, mixed> $data
   *   Data produced by toArray().
   */
  public static function fromArray(array $data): self {
    $set = new self();
    $set->rules = array_values(array_map([Rule::class, 'fromArray'], $data['rules']));
    $set->realByDefault = $data['realByDefault'];
    return $set;
  }

  /**
   * Adds a rule for the commands containing all the given arguments.
   *
   * @param string ...$tokens
   *   Arguments the command must contain; none matches every command.
   */
  public function on(string ...$tokens): Rule {
    $rule = new Rule(array_values($tokens));
    $this->rules[] = $rule;
    return $rule;
  }

  /**
   * Sends the commands no rule matches to the real tool.
   */
  public function delegateToRealTool(): self {
    $this->realByDefault = TRUE;
    return $this;
  }

  /**
   * Whether commands no rule matches go to the real tool.
   */
  public function isRealByDefault(): bool {
    return $this->realByDefault;
  }

  /**
   * Gets the rules, keyed by their position.
   *
   * @return list<\DrupalUpdater\Tests\Simulator\Rule>
   *   Rules.
   */
  public function rules(): array {
    return $this->rules;
  }

  /**
   * Gets the array form, stored in the scenario file.
   *
   * @return array<string, mixed>
   *   Rule set data.
   */
  public function toArray(): array {
    return [
      'rules' => array_map(fn (Rule $rule) => $rule->toArray(), $this->rules),
      'realByDefault' => $this->realByDefault,
    ];
  }

}
