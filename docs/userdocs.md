# Deferred feedback for AI grading — user guide

This question behaviour is used by the AI Text question type (`qtype_aitext`).
It works like Moodle's standard *Deferred feedback* behaviour: students answer
all questions, submit the attempt, and then see their grades and feedback. The
difference is in how the AI-generated grade and feedback are handled.

You do not choose this behaviour in the quiz settings. AI Text questions select
it automatically.

## What it does

When a student finishes an attempt, each AI Text response is sent to the AI
backend with the teacher's marking instructions. The AI returns:

- a grade for the response
- written feedback for the student
- optionally, a spelling and grammar correction

The behaviour stores these results inside the question attempt, through the
normal question engine, in the same way that Moodle stores any other grade or
comment. As a result:

- the AI feedback appears in the attempt review like any other feedback
- the prompt that was sent to the AI is kept, so teachers can see what the AI
  was asked
- teachers can override the AI. A manual comment or mark from a teacher always
  takes priority over the AI's. When a teacher opens the manual grading form,
  the comment box is pre-filled with the AI feedback so it can be edited rather
  than retyped
- teachers can edit the spelling and grammar correction, and the edit is
  recorded in the attempt history as "Teacher edited spellcheck"
- regrading and backup and restore work normally, because everything is
  stored in the standard question attempt data

## When grading happens

The AI grades every response while the "Submit all and finish" request is
running. The student waits for every AI call to complete, then goes straight to
a fully graded review page.

This suits small classes, or sites where students finish at different times.
For large classes, or a slow AI backend, install the *Queued AI grading*
behaviour (`qbehaviour_queued_for_aitext`). When it is enabled, AI Text
responses are graded by a background task instead, and the student does not
wait for the AI when submitting.

## Benefits

- **Reliable storage.** AI results are saved through the question engine, not
  through direct database writes, so they behave the same way as any other
  question data during regrades, backups, restores and privacy requests.
- **Transparency.** The prompt sent to the AI is kept with each response, which
  makes it easier to understand and check AI grades.
- **Teacher control.** Teachers can review, edit or replace any AI grade or
  comment, and their changes always take priority.

## Requirements

- Moodle 4.5 or later
- The AI Text question type (`qtype_aitext`)
