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

## Grading modes

Site administrators can pick one of two grading modes at
*Site administration > Plugins > Question behaviours > Deferred feedback for AI grading*.

### Immediate grading (default)

The AI grades every response while the "Submit all and finish" request is
running. The student waits for every AI call to complete, then goes straight to
a fully graded review page.

This is the simplest setup and suits small classes, or sites where students
finish at different times.

### Asynchronous grading

Enable **Asynchronous AI grading** to grade responses in the background.

1. The student clicks "Submit all and finish". The attempt is submitted at
   once, without waiting for the AI.
2. Each AI Text response is marked **Requires grading** and a background task
   is queued for it.
3. The review page shows a notice on each waiting question, and a progress
   banner at the top, for example: *"Your answers have been submitted. 2 of 5
   graded by AI so far."* The page checks for progress every 10 seconds.
4. Moodle's cron runs the tasks, which call the AI backend. The number of
   tasks running at once is limited by the concurrency setting.
5. When a response has been graded, the attempt history shows a "Graded by AI"
   step. The quiz attempt total and the student's gradebook grade are updated.
6. When all responses have been graded, the review page reloads by itself and
   shows the grades and feedback.

Students can also close the page and come back later. Their grades will be
there once the tasks have run.

#### Settings

| Setting | Description |
|---------|-------------|
| Asynchronous AI grading | Turns background grading on or off. Off by default. |
| Maximum concurrent AI grading tasks | The maximum number of grading tasks that run at the same time, which is also the maximum number of requests sent to the AI backend at once. The default is 2. 0 means no limit. Keep this below the site's *Ad hoc task runner limit* (`task_adhoc_concurrency_limit`) so other background tasks are not held up. |

#### Requirements

- **Cron must run frequently.** Responses stay in the "Requires grading" state
  until cron picks up their tasks. If cron only runs every few minutes,
  students will wait at least that long for feedback.
- The review page stops checking for progress after 30 minutes. After that,
  students have to reload the page themselves.

#### What happens if grading fails

If the AI backend does not return a grade, for example because of a timeout or
a rate limit, the task is retried automatically, with an increasing delay
between attempts. After 5 failed attempts the response is left in the
"Requires grading" state, so a teacher can grade it manually.

If a teacher grades a response manually while it is still waiting for the AI,
the teacher's grade is kept and the AI result is discarded when it arrives.

The background tasks run as the student who submitted the attempt, so any
per-user permissions or quotas configured for the AI backend still apply.

## Benefits

### All modes

- **Reliable storage.** AI results are saved through the question engine, not
  through direct database writes, so they behave the same way as any other
  question data during regrades, backups, restores and privacy requests.
- **Transparency.** The prompt sent to the AI is kept with each response, which
  makes it easier to understand and check AI grades.
- **Teacher control.** Teachers can review, edit or replace any AI grade or
  comment, and their changes always take priority.

### Asynchronous mode

- **No load spike at the end of an exam.** When a whole class submits at the
  same moment, immediate grading sends every request to the AI backend at
  once. This can cause timeouts, rate limit errors and failed submissions.
  Asynchronous grading spreads the requests over time, up to the configured
  concurrency limit.
- **Fast submission for students.** "Submit all and finish" no longer waits for
  the AI, so the request completes quickly even with many questions, a slow
  model or a busy backend. Students are no longer left looking at a page that
  appears to hang.
- **Automatic recovery.** Temporary AI failures are retried instead of losing
  the grade, and a response that cannot be graded falls back to manual grading.
- **Regrades do not call the AI again.** The AI result is saved as part of the
  attempt history, so a quiz regrade reuses it rather than sending a new
  request, which would cost money and could give a different grade.
- **Predictable costs and quotas.** The concurrency limit gives administrators
  direct control over how hard the AI backend is used.

## Requirements

- Moodle 4.5 or later
- The AI Text question type (`qtype_aitext`)
- For asynchronous grading, a frequently running cron
