# qbehaviour_for_aitext

Question behaviour plugin for Moodle that extends deferred feedback to properly
persist AI-generated grading data via the question engine API.

## Purpose

Designed exclusively for use with `qtype_aitext`. Intercepts the grading step to
write AI results (feedback, prompt, spellcheck) as cached behaviour variables on
the pending step — eliminating raw database writes from within the question type.

## How it works

When a quiz attempt finishes, `process_finish()` calls the parent grading flow,
then reads AI results cached on the question object and writes them to the step:

| Variable              | Content                                      |
|-----------------------|----------------------------------------------|
| `_comment`            | AI-generated feedback (HTML)                 |
| `_commentformat`      | Format constant (`FORMAT_HTML`)              |
| `_aiprompt`           | Full prompt sent to the AI                   |
| `_spellcheckresponse` | Grammar/spelling correction (if enabled)     |

Teacher manual comments (`comment`, `mark`) take priority over AI vars in the
renderer.

## Requirements

- Moodle 4.5 or later
- `qtype_aitext` question type

## Installation

Place in `question/behaviour/deferred_for_aitext/`. Run Moodle upgrade.

This behaviour is not archetypal — it does not appear in quiz settings. It is
selected automatically by `qtype_aitext_question::make_behaviour()`.

## License

GNU GPL v3 or later — http://www.gnu.org/copyleft/gpl.html

Copyright 2026 ISB Bayern
