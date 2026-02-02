

# Laravel & PHP Guidelines for AI Code Assistants

This file contains Laravel and PHP coding standards optimized for AI code assistants like Claude Code, GitHub Copilot, and Cursor. These guidelines are derived from Spatie's comprehensive Laravel & PHP standards.

## Core Laravel Principle

**Follow Laravel conventions first.** If Laravel has a documented way to do something, use it. Only deviate when you have a clear justification.

## PHP Standards

- Follow PSR-1, PSR-2, and PSR-12
- Use camelCase for non-public-facing strings
- Use short nullable notation: ___SINGLE_BACKTICK___?string___SINGLE_BACKTICK___ not ___SINGLE_BACKTICK___string|null___SINGLE_BACKTICK___
- Always specify ___SINGLE_BACKTICK___void___SINGLE_BACKTICK___ return types when methods return nothing

## Class Structure
- Use typed properties, not docblocks:
- Constructor property promotion when all properties can be promoted:
- One trait per line:

## Type Declarations & Docblocks
- Use typed properties over docblocks
- Specify return types including ___SINGLE_BACKTICK___void___SINGLE_BACKTICK___
- Use short nullable syntax: ___SINGLE_BACKTICK___?Type___SINGLE_BACKTICK___ not ___SINGLE_BACKTICK___Type|null___SINGLE_BACKTICK___
- Document iterables with generics:

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  /** @return Collection<int, User> */
  public function getUsers(): Collection
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___


### Docblock Rules
- Don't use docblocks for fully type-hinted methods (unless description needed)
- **Always import classnames in docblocks** - never use fully qualified names:

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  use \Spatie\Url\Url;
  /** @return Url */
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

- Use one-line docblocks when possible: ___SINGLE_BACKTICK___/** @var string */___SINGLE_BACKTICK___
- Most common type should be first in multi-type docblocks:

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  /** @var Collection|SomeWeirdVendor\Collection */
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

- If one parameter needs docblock, add docblocks for all parameters
- For iterables, always specify key and value types:

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  /**
   * @param array<int, MyObject> $myArray
   * @param int $typedArgument
   */
  function someFunction(array $myArray, int $typedArgument) {}
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

- Use array shape notation for fixed keys, put each key on it's own line:

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  /** @return array{
     first: SomeClass,
     second: SomeClass
  } */
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___


## Control Flow
- **Happy path last**: Handle error conditions first, success case last
- **Avoid else**: Use early returns instead of nested conditions
- **Separate conditions**: Prefer multiple if statements over compound conditions
- **Always use curly brackets** even for single statements
- **Ternary operators**: Each part on own line unless very short


___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
// Happy path last
if (! $user) {
    return null;
}

if (! $user->isActive()) {
    return null;
}

// Process active user...

// Short ternary
$name = $isFoo ? 'foo' : 'bar';

// Multi-line ternary
$result = $object instanceof Model ?
    $object->name :
    'A default value';

// Ternary instead of else
$condition
    ? $this->doSomething()
    : $this->doSomethingElse();
___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___


## Laravel Conventions

### Routes
- URLs: kebab-case (___SINGLE_BACKTICK___/open-source___SINGLE_BACKTICK___)
- Route names: camelCase (___SINGLE_BACKTICK___->name('openSource')___SINGLE_BACKTICK___)
- Parameters: camelCase (___SINGLE_BACKTICK___{userId}___SINGLE_BACKTICK___)
- Use tuple notation: ___SINGLE_BACKTICK___[Controller::class, 'method']___SINGLE_BACKTICK___

### Controllers
- Plural resource names (___SINGLE_BACKTICK___PostsController___SINGLE_BACKTICK___)
- Stick to CRUD methods (___SINGLE_BACKTICK___index___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___create___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___store___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___show___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___edit___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___update___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___destroy___SINGLE_BACKTICK___)
- Extract new controllers for non-CRUD actions

### Configuration
- Files: kebab-case (___SINGLE_BACKTICK___pdf-generator.php___SINGLE_BACKTICK___)
- Keys: snake_case (___SINGLE_BACKTICK___chrome_path___SINGLE_BACKTICK___)
- Add service configs to ___SINGLE_BACKTICK___config/services.php___SINGLE_BACKTICK___, don't create new files
- Use ___SINGLE_BACKTICK___config()___SINGLE_BACKTICK___ helper, avoid ___SINGLE_BACKTICK___env()___SINGLE_BACKTICK___ outside config files

### Artisan Commands
- Names: kebab-case (___SINGLE_BACKTICK___delete-old-records___SINGLE_BACKTICK___)
- Always provide feedback (___SINGLE_BACKTICK___$this->comment('All ok!')___SINGLE_BACKTICK___)
- Show progress for loops, summary at end
- Put output BEFORE processing item (easier debugging):

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  $items->each(function(Item $item) {
      $this->info("Processing item id ___SINGLE_BACKTICK___{$item->id}___SINGLE_BACKTICK___...");
      $this->processItem($item);
  });

  $this->comment("Processed {$items->count()} items.");
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___


## Strings & Formatting

- **String interpolation** over concatenation:

## Enums

- Use PascalCase for enum values:

## Comments

- **Avoid comments** - write expressive code instead
- When needed, use proper formatting:

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  // Single line with space after //

  /*
   * Multi-line blocks start with single *
   */
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

- Refactor comments into descriptive function names

## Whitespace

- Add blank lines between statements for readability
- Exception: sequences of equivalent single-line operations
- No extra empty lines between ___SINGLE_BACKTICK___{}___SINGLE_BACKTICK___ brackets
- Let code "breathe" - avoid cramped formatting

## Validation

- Use array notation for multiple rules (easier for custom rule classes):

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  public function rules() {
      return [
          'email' => ['required', 'email'],
      ];
  }
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

- Custom validation rules use snake_case:

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___php
  Validator::extend('organisation_type', function ($attribute, $value) {
      return OrganisationType::isValid($value);
  });
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___


## Blade Templates

- Indent with 4 spaces
- No spaces after control structures:

  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___blade
  @if($condition)
      Something
  @endif
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___


## Authorization

- Policies use camelCase: ___SINGLE_BACKTICK___Gate::define('editPost', ...)___SINGLE_BACKTICK___
- Use CRUD words, but ___SINGLE_BACKTICK___view___SINGLE_BACKTICK___ instead of ___SINGLE_BACKTICK___show___SINGLE_BACKTICK___

## Translations

- Use ___SINGLE_BACKTICK_____()___SINGLE_BACKTICK___ function over ___SINGLE_BACKTICK___@lang___SINGLE_BACKTICK___:

## API Routing

- Use plural resource names: ___SINGLE_BACKTICK___/errors___SINGLE_BACKTICK___
- Use kebab-case: ___SINGLE_BACKTICK___/error-occurrences___SINGLE_BACKTICK___
- Limit deep nesting for simplicity:
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___
  /error-occurrences/1
  /errors/1/occurrences
  ___SINGLE_BACKTICK______SINGLE_BACKTICK______SINGLE_BACKTICK___

## Testing

- Keep test classes in same file when possible
- Use descriptive test method names
- Follow the arrange-act-assert pattern

## Quick Reference

### Naming Conventions
- **Classes**: PascalCase (___SINGLE_BACKTICK___UserController___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___OrderStatus___SINGLE_BACKTICK___)
- **Methods/Variables**: camelCase (___SINGLE_BACKTICK___getUserName___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___$firstName___SINGLE_BACKTICK___)
- **Routes**: kebab-case (___SINGLE_BACKTICK___/open-source___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___/user-profile___SINGLE_BACKTICK___)
- **Config files**: kebab-case (___SINGLE_BACKTICK___pdf-generator.php___SINGLE_BACKTICK___)
- **Config keys**: snake_case (___SINGLE_BACKTICK___chrome_path___SINGLE_BACKTICK___)
- **Artisan commands**: kebab-case (___SINGLE_BACKTICK___php artisan delete-old-records___SINGLE_BACKTICK___)

### File Structure
- Controllers: plural resource name + ___SINGLE_BACKTICK___Controller___SINGLE_BACKTICK___ (___SINGLE_BACKTICK___PostsController___SINGLE_BACKTICK___)
- Views: camelCase (___SINGLE_BACKTICK___openSource.blade.php___SINGLE_BACKTICK___)
- Jobs: action-based (___SINGLE_BACKTICK___CreateUser___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___SendEmailNotification___SINGLE_BACKTICK___)
- Events: tense-based (___SINGLE_BACKTICK___UserRegistering___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___UserRegistered___SINGLE_BACKTICK___)
- Listeners: action + ___SINGLE_BACKTICK___Listener___SINGLE_BACKTICK___ suffix (___SINGLE_BACKTICK___SendInvitationMailListener___SINGLE_BACKTICK___)
- Commands: action + ___SINGLE_BACKTICK___Command___SINGLE_BACKTICK___ suffix (___SINGLE_BACKTICK___PublishScheduledPostsCommand___SINGLE_BACKTICK___)
- Mailables: purpose + ___SINGLE_BACKTICK___Mail___SINGLE_BACKTICK___ suffix (___SINGLE_BACKTICK___AccountActivatedMail___SINGLE_BACKTICK___)
- Resources/Transformers: plural + ___SINGLE_BACKTICK___Resource___SINGLE_BACKTICK___/___SINGLE_BACKTICK___Transformer___SINGLE_BACKTICK___ (___SINGLE_BACKTICK___UsersResource___SINGLE_BACKTICK___)
- Enums: descriptive name, no prefix (___SINGLE_BACKTICK___OrderStatus___SINGLE_BACKTICK___, ___SINGLE_BACKTICK___BookingType___SINGLE_BACKTICK___)

### Migrations
- do not write down methods in migrations, only up methods

### Code Quality Reminders

#### PHP
- Use typed properties over docblocks
- Prefer early returns over nested if/else
- Use constructor property promotion when all properties can be promoted
- Avoid ___SINGLE_BACKTICK___else___SINGLE_BACKTICK___ statements when possible
- Use string interpolation over concatenation
- Always use curly braces for control structures

---

*These guidelines are maintained by [Spatie](https://spatie.be/guidelines) and optimized for AI code assistants.*
<?php /**PATH /Users/travishayes/srv/wholefoods-data/storage/framework/views/fb95572f421ac0dc1a074a8a19144ee0.blade.php ENDPATH**/ ?>