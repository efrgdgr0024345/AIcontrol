# Investigation UX: findings and recommended direction

Review date: 10 November 2026. Implementation reviewed: `73e85b8`, subsequently
merged into main. This is a targeted literature, product and source-code review,
not a systematic review or a live-host usability study. Proposals below have not
been implemented or validated with users. No billed investigation was performed.

**Recommendation:** develop a question-centred investigation workbench. Its main
activity is comparing explanations, choosing a discriminating test, and recording
what changed. Keep the current map as an overview of the same objects, and keep
the bounded phase engine underneath. Prioritise evidence attribution and cheap,
reversible thinking moves before more canvas functionality.

## A. Diagnosis of the current pathway

### Correct the brief's baseline

The brief says the spatial map and click-to-search controls do not yet exist.
They are present in the reviewed release: Map/List, inspector, filters, claim
selection, focused Evidence requests and source-selected Learn. Rebuilding these
would duplicate work. The unanswered question is whether these controls support
better investigation and learning, rather than merely expose execution.

### What already works

The product has valuable foundations: explicit unchecked priors, a human review
pause, source/run ownership checks, bounded searches, retained dead ends,
source-scoped Learn, and Stop that cannot be overwritten by a late answer.
These make consequences legible and prevent an attractive interface from silently
spending indefinitely. Inspecting and selecting existing material costs no model
request. These foundations should survive the next design.

### The main gaps

1. **The model chooses most of the starting ideas.** The user selects among its
   hypotheses, but cannot yet author and revise explanations as first-class
   objects. Model confidence and default selection can frame the investigation
   before the user has expressed a view. Selection is real agency, but limited
   agency over the question, explanation and test design.
2. **The graph mainly represents execution lineage.** `parent_id` says where a
   node came from. It does not say that an observation supports, challenges or
   qualifies an explanation. More branching cannot supply those missing meanings.
3. **Citation integrity is stronger than evidence attribution.** In
   `Investigation.php`'s Evidence branch, the same first 1,200 bytes of the overall
   answer are attached to each citation card. The label honestly calls this a
   provider summary. Nevertheless, comparing several such cards can look like
   comparing independent source findings when they repeat one search summary.
   Reconciliation checks that source IDs exist and relate to the claim; it does
   not establish that their content entails the verdict. This is the most
   consequential gap to fix before making evidence look more authoritative.
4. **The run is too close to being the unit of thought.** Real inquiry outlives a
   five-request execution sequence. Explanations need revisions, branches need
   parking, and questions need to be revisited without restarting the conceptual
   work. Current client state such as collapsed branches is not a durable inquiry
   record. Do not confuse keeping a node with keeping why it mattered.
5. **The budget is visible but not fully explained as a plan.** A two-search round
   uses five requests: Dream + Plan + Evidence + Evidence + Reconciliation. Learn
   is another request. Two such rounds consume the whole ten-request window.
   The user needs to see the cost of reaching a useful stopping point, not just
   the number remaining before the next click.
6. **Operational completion can be mistaken for knowing.** A complete node can be
   a generated, unchecked claim. An open claim can be a successful investigation
   outcome. A failed request is not scientific counter-evidence. These meanings
   need separate presentation.

### Agency by moment

| Moment | Current strength | Next improvement |
|---|---|---|
| Dream review | Human chooses what proceeds | Let the human add/reframe an explanation; make drafts reversible |
| Evidence steering | Choice changes the next bounded search | State what the test would distinguish and what result would matter |
| Learn | Selected-source context is narrow and attributable | Add optional explanation-from-memory before AI feedback |
| Stop | Honest execution/billing semantics | Preserve an intelligible next step, not only interrupted status |
| Dead end | Not erased by synthesis | Record the search's scope and why it did not resolve the question |
| Reconciliation | Per-claim outcomes and source IDs | Separate model assessment, human judgement and unresolved scope |

## Research synthesis: mechanisms worth borrowing

| Evidence or precedent | Relevant finding/mechanism | Implication and limit |
|---|---|---|
| Guided inquiry meta-analysis [1] | Guidance improved learning in the studies reviewed | Offer useful test prompts and examples; a blank canvas alone is insufficient. Classroom findings do not estimate AIcontrol's effect. |
| Productive failure meta-analysis [2] | Attempting a problem before instruction can help under appropriate design conditions | Invite an initial explanation, then provide consolidation. Wasted API calls are not automatically productive failure. |
| Concept-map meta-analysis [3] | Node-link learning activities can help; results depend on use and comparison | Let users construct relationships. Merely viewing a generated graph is a different intervention. |
| Retrieval experiment [4] | Retrieval/reconstruction outperformed concept-map elaboration in the tested science-learning tasks | Evaluate learning separately from visual appeal; add optional teach-back. This does not establish that maps are generally inferior. |
| Epistemic action [5] | Physical manipulation in Tetris can reduce a cognitive task's difficulty | Grouping and comparing objects should help answer a question, not just decorate the screen. Transfer to research UI is a design hypothesis. |
| Play and self-determination [6] | Perceived autonomy and competence were associated with enjoyment and motivation in the game studies | Provide meaningful choices and understandable consequences, not a points system. Enjoyment is not evidence of research accuracy. |
| Knowledge building [7] | The framework emphasises improvable ideas and control over inquiry goals, strategies and evaluation | Keep explanations revisable and preserve why they changed. Its community pedagogy does not require adding multi-user features here. |
| Cognitive forcing with AI [8] | Interventions reduced overreliance in a specific experiment, with usability and individual-difference trade-offs | Put a small reasoning prompt at a consequential decision; do not impose a worksheet before every click. |
| ResearchMaps [9] | Empirical and hypothetical relations have explicit meanings and provenance | Borrow typed relations, not its domain-specific biological scoring or a generic numerical truth score. |
| Evidence gap maps [10] | They show the distribution of identified evidence through a transparent search framework | Show coverage and gaps; two web searches cannot substantiate a claim that the wider literature contains no evidence. |
| Obsidian Canvas / Kialo [11,12] | Persistent arrangements and explicit pro/con structure are deployed interaction patterns | Borrow stable arrangement and explicit argument roles. Product pages demonstrate features, not independent learning outcomes. |
| Outer Wilds [13] | A mystery invites revisiting a world with tools and questions | Borrow curiosity and revisitation. Real research has no authored answer key or guaranteed solvable ending. |

The common thread is active construction and revision with useful feedback.
Neither unrestricted exploration nor maximal automation is the conclusion of
this evidence. Expert inquiry also involves reframing what a result means, not
only collecting another result. The workbench must support that reframing.

## B. Ten actionable, testable principles

| Principle | Product rule | Test |
|---|---|---|
| Own the question | Allow an optional human explanation before or alongside Dream | Can the user pursue an explanation absent from the model's initial list? |
| Make cheap moves plentiful | Group, annotate, compare and revise drafts without a model call | Request count does not change during those actions |
| Make costly moves explicit | Show purpose, target and request allowance before a test | The user can predict what the click will send and spend |
| Keep provenance visible | Separate located URL, answer summary and source passage | Users can distinguish them in a fixture with misleading citations |
| Treat ideas as revisable | Freeze executed revisions, not all future thinking | Revision leaves old evidence and judgements attached to their original version |
| Reward clarification | A narrowed explanation or well-scoped unknown can end a round | Users do not need a supported verdict to make useful progress |
| Prefer discriminating tests | Ask what finding would separate competing explanations | A test can produce a result that changes the user's current view |
| Keep judgements attributable | Label model suggestions and human assessments separately | No human acceptance is inferred from opening or completing a run |
| Keep failure interpretable | Distinguish no useful citation, contradiction and transport uncertainty | No API failure is counted as evidence against an explanation |
| Preserve equivalent access | Every semantic move has a form/list equivalent | Keyboard and no-JS users can make the same research decisions |

## C. Recommended core model: the investigation workbench

The mental model is: **I am improving an explanation of a question. Each search
is a test of something specific; each result may change, narrow or leave it open.**

One primary workspace contains explanations, tests, collected material and current
judgements. The map is an overview of their relationships. The execution tree is
an inspectable history, rather than the primary organising metaphor.

### Objects and selection semantics

- **Question:** decision, scope and relevant context. Selecting it reveals open
  issues and prior rounds; it does not start a provider call.
- **Explanation:** human- or model-authored, versioned and explicitly unchecked
  until assessed. Selecting it focuses relevant tests and evidence. It does not
  mean endorsement. Editing creates a draft revision, not a rewrite of a result.
- **Test:** target explanation revision(s), query, purpose, expected distinguishing
  results and actual request state. Choosing it prepares the next bounded search.
  A separate Run test action commits it.
- **Evidence item:** an attributable observation, answer span or available passage,
  linked to a citation card. Selecting it reveals precisely what was obtained.
- **Relation:** supports, challenges or qualifies, with a rationale and author.
  Drawing a relation records an interpretation, never proof.
- **Judgement:** the model assessment and the user's current interpretation, with
  scope and caveats. One must not silently overwrite the other.
- **Parked question:** a question and reason to return. Parking is a workflow
  choice, not an epistemic verdict or a failed provider operation.

Separate three dimensions: execution status (existing statuses), epistemic
assessment (unchecked/supported/weakened/contradicted/open), and human workflow
(draft/chosen/parked/superseded). Keep the existing execution enums intact.

### The playable loop

1. Express or choose a question and an optional initial explanation.
2. Consider the model's unchecked alternatives alongside the user's own ideas.
3. Pick the uncertainty worth resolving, rather than automatically excluding all
   low-confidence ideas. Low confidence can signal a useful question.
4. Prepare a test: what result would support, weaken or fail to distinguish these
   explanations? This is a local drafting move.
5. Run one bounded search with visible request consequences.
6. Inspect what was actually obtained and attach a scoped interpretation.
7. Choose the next test, finish this round, or park the question.
8. Record what changed. Optionally explain the selected evidence in the user's own
   words, then use Learn to obtain evidence-scoped feedback.

Retain Dream → review → Plan → Evidence → Reconciliation as the execution engine.
Expand its human decision points; do not replace it with an autonomous graph
walker. A persisted review gate should precede each next search and any optional
follow-up spending. Refreshing must not bypass that decision. Finishing early
can skip unused planned slots with explicit reasons and reconcile the collected
material; it must not pretend skipped tests ran.

### Budget as an understandable resource

Show planned round cost as well as hourly capacity. Under the current engine a
one-search round needs four requests, a two-search round five, and Learn one more.
Make the selected search allowance a validated server field so a one-search plan
cannot silently generate two steps. Before starting, explain whether the remaining
budget can support that path. Never promise a monetary cost from request counts;
input tokens, tool charges and actual usage are separate.

There is no need for a complex new reservation service initially: one active run
already prevents competing legacy/Learn work. Preflight admission, record the
round allowance, and enforce each request in the existing transaction. A failed
or uncertain call still consumes the applicable hourly allowance. No undo refunds
it. Retrying is an explicit new operation, not a hidden continuation.

### An illustrative research move

Suppose the question is whether a household's poor solar payback is mainly due to
low daytime self-consumption or a high installation quote. Both could matter;
the user should not be forced to treat them as mutually exclusive.

A useful test asks which household and tariff information would separate their
contributions. A tariff page may establish a price but cannot establish that
household's consumption. The workbench should therefore show a relevant source
and an outstanding local-data requirement, rather than colour the broad claim
supported. Revising the explanation to a narrower conditional statement is useful
progress. This example is illustrative, not a finding about solar economics.

## D. Interaction catalogue and minimum server truth

Cost below means provider requests. A local action is not a promise of zero
storage/computation cost or a claim that external site visits are free.

| Move | Intent and cost | Minimum persisted truth | Failure or uncertain behaviour |
|---|---|---|---|
| Add/reframe an explanation | State a view; 0 for human text | Author, revision, question, predecessor and time | Failed save does not change the committed version |
| Compare two explanations | Identify differences; 0 for a local comparison | IDs/revisions and optional comparison note | No generated analysis is implied |
| Group, pin or rearrange | Make a relationship easier to see; 0 | Layout/group metadata, separate from evidence | Can be undone without modifying execution history |
| Select next test | Prepare consequential work; 0 until Run | Target revisions, intent, query and allowance | Selection alone cannot send a request |
| Challenge/search a direction | Seek discriminating material; 1, at most one web tool call | Request ID, target, focus note, search slot and result status | Stop/timeout may leave usage unknown; no late application or automatic retry |
| Attach support/challenge/qualification | Interpret collected material; 0 for human annotation | Evidence/source IDs, exact claim revision, author, scope and rationale | Does not independently verify the source or promote a URL into evidence |
| Park/restore a branch | Save a reason to return; 0 | Park reason, unresolved issue, origin and later restoration | Does not resume an interrupted request; a new round is explicit |
| Explain/compare selected sources | Learn from evidence; 1 | Selected run-local source IDs, action, question and result | No Dream, vault notes, other projects or search tool in context |
| Teach back | Retrieve understanding; 0 for local answer, 1 for optional Learn check | Human answer, selected sources, optional feedback request | Feedback is model assessment, not a certified comprehension score |
| Finish/export a trail | Consolidate; export 0; Reconciliation 1 if not already performed | Executed/skipped tests, scoped judgements, revisions, real URLs and token/unknown-usage record | Exporting must not start synthesis or mark unfinished work complete |

A model-generated new explanation or comparison is not a zero-cost local move.
It must use an explicit bounded provider operation. Likewise, comparing
explanations with AI is not source-only Learn: that belongs to an appropriate
Reconciliation operation with explicit context and request accounting.

Undo is for uncommitted drafts and presentation. Committed findings get a new
revision or correction event; the old request, source and bill are not rewritten.

## E. Information architecture and progressive enhancement

Desktop regions:

```text
Question and scope                  Hourly capacity / planned round cost / Stop
Working explanations               Next test and what would distinguish them
Collected material + limitations    Inspector: provenance, relation and judgement
What changed / unresolved           Parked questions / previous bounded rounds
```

Use the existing inspector, source components and map machinery. Default to a
small comparison board for the active question; allow Map to show the wider
relationship structure. Keep phase/request history behind details. Stable object
positions matter more than animated relayout whenever another node arrives.

Prior, material and judgement need text labels and shape/placement differences,
not colour alone. Put model confidence in secondary detail; it is not calibrated
source quality and should not dominate which hypotheses deserve testing.

On mobile, show one focused explanation/test and its linked evidence, with a
comparison selector and an inspector below it. Preserve a reachable Stop and
budget summary without obscuring the material. Do not require pinch-zoom to read.

Full spatial UI, board/list and pure forms must operate on the same IDs, revision
rules and server actions. No-JS must eventually have the same author/park/test
moves, not merely a transcript. Dragging gets an equivalent form. Polling must
preserve focus and selections; viewport movement must never submit an operation.

## F. Fit to the existing implementation

### Evidence attribution first

Immediately store the overall Evidence response as a search-level summary. Stop
presenting that same summary as if it were each source's individual finding.
Retain the located citation cards and their genuine provenance. If no specific
passage was obtained, say so. Existing cards can remain readable with a legacy
summary label; do not invent per-source passages during migration.

Then add evidence items with source ID, producing request ID, content kind,
available locator, captured text, origin and scope. An annotated span of the AI
answer is not a quotation from the source page. A source passage must be recorded
only if actually available. A human-pasted quotation needs human provenance; it
is not independently verified merely because it was pasted.

The existing citation gate stays unchanged. A typed URL or note cannot become a
source card. No arbitrary page-fetching worker or new browsing tool is required.
If richer per-source output cannot be obtained within the current tool/API
contract and 1,600-token cap, retain the explicit missing-passage state rather
than fabricating specificity.

### Minimal additive data model

- Keep `research_runs`, `research_nodes`, `research_sources`, `research_requests`
  and historical `parent_id` links. Runs remain execution records.
- Use `plan_step.data_json` initially for test intent, targets, distinguishing
  outcomes and skipped/replaced-slot reasons.
- Add versioned question/claim drafts, with human/model origin and provenance to
  committed run nodes. Do not rewrite old claims to fit a newer conclusion.
- Add `research_evidence_items` and `research_relations` for attributable material
  and many-to-many interpretations. A source may relate differently to several
  claims; a single tree parent cannot encode that.
- Add workflow/layout metadata for parking, grouping and stable positions. Parked
  is not a new provider result status.
- Record model and human judgements separately. A useful relation includes its
  author, target revision and rationale, not just an arrow type.

An initial implementation can use JSON metadata for new bounded fields, while
promoting repeatedly queried relations/revisions into tables. Avoid turning JSON
into a second, unvalidated database. All mutations still require PIN/CSRF and
run/project ownership checks. New rows and columns must remain harmless to older
code; rollback does not reverse private-data migrations.

### Carrying inquiry across rounds

Preserve a project-level question and frontier; start any new provider run
explicitly. Do not revive a stopped/completed run. Later, a new run can explicitly
carry a previous claim revision and source references from the same project.
To preserve current Learn scope, carried cards need validated run-local IDs and
original citation provenance. Label them reused, not newly searched or retrieved.
Do not silently allow Learn to range over other runs/projects.

### Poll, Stop and global bounds

Continue one phase per PHP request with session release before I/O. Keep atomic
worker claims, bounded responses, late-result refusal and no automatic retry.
Free board actions can work while a request is running; they must not mutate that
request's target revisions. A parked branch does not cancel a request or remove
the workspace's active-run lock. Closing a running round remains explicit.

No core recommendation requires a daemon, simultaneous agents, more than two
searches per round, a larger hourly allowance or new provider tools. Persisted
human-review gates and round-cost admission are real server work, not CSS-only
changes. Unlimited click-to-expand would relax a hard constraint and is not
recommended.

## G. Strong alternatives rejected

| Alternative | Strength | Why it is not the primary recommendation |
|---|---|---|
| Pure expanding mind map | Appealing overview and spatial navigation | Does not itself distinguish argument meaning from execution lineage; can reward expansion and become difficult on mobile |
| Linear guided wizard | Clear next step and cheap implementation | Reframing, side-by-side comparison and returning to parked ideas remain awkward |
| Autonomous expedition with a final dossier | Low interaction burden | Weakens the requested agency, strains bounds and makes persuasive fluency too easy to mistake for knowledge |
| Detective game with scores and solved cases | Strong motivation and feedback in authored worlds | Open research has no guaranteed answer key; source counts and green verdicts would reward the wrong achievements |

An unconstrained infinite canvas is also a poor first step: it requires users to
invent the inquiry method while arranging objects. The recommendation combines a
small, guided work surface with optional spatial overview, not a generic whiteboard.

## H. Phased pathway

1. **Make the evidence display trustworthy.** Separate batch summaries from source
   findings; label execution, prior and judgement distinctly; retain legacy data.
   Acceptance: users can tell what was and was not obtained from each cited URL.
2. **Add reversible human thinking moves.** Human explanation drafts, scoped
   revisions, comparison and parking; reuse existing board/map components.
   Acceptance: a human idea can drive a test without appearing in the initial
   Dream list, and revision preserves old attributions.
3. **Make test choice explicit and budget-complete.** Test intent and distinguishing
   outcomes, validated one/two-search allowance, preflight cost and persisted
   review/finish gates. Acceptance: refresh cannot spend a search; finishing early
   does not claim unused tests ran or consume their request slots.
4. **Close the learning loop.** Selected-evidence comparison, optional teach-back,
   human judgement and a concise what-changed record. Acceptance: Learn still
   receives only permitted evidence context, and local reflection works when no
   source exists.
5. **Preserve inquiry across explicit rounds.** Stable question history, parked
   frontiers, carefully attributed source reuse and a richer optional map.
   Acceptance: new work is a new bounded run; no historical or uncertain request
   is revived or relabelled.

Each phase needs meaningful PHP/HTTP tests, keyboard/no-JS checks, and task-level
usability evaluation. Avoid adding features merely to make the map look busier.
No reliable delivery estimate is possible before choosing the first slice and
checking real provider output. Do not promise the whole redesign as a UI patch.

## I. Evaluation

Start with the owner using matched investigation tasks and identical fixture
material in the current map and a workbench prototype. Counterbalance order and
use different but comparable questions. Separate UI evaluation from content/model
quality by keeping source packets constant. If broader use is intended, add a
small formative usability study; a private single-user product need not add
multi-user infrastructure to test its design.

Useful fixture cases include a valid citation that does not support the claim,
two URLs repeating one underlying study, context-dependent disagreement, a
completed search without citations, and an uncertain request. A source-reading
rubric, not the AI's own verdict, should establish expected interpretations.

Observe whether the person can:

- explain why the next test would distinguish their alternatives;
- identify prior versus source material versus judgement;
- reject an attractive but irrelevant citation;
- return to a dead end without mistaking it for a refuted hypothesis;
- reconstruct what changed and what remains unknown;
- perform the same semantic moves by keyboard and forms.

Lightweight private measures: interpretation accuracy against the rubric, useful
question refinement per provider request, agreement between predicted and actual
next action, recovery from misleading evidence, and optional delayed explanation
or transfer to a related question. Record small samples as formative observations,
not a statistically proven improvement. Opening a link is not proof of reading;
a fluent teach-back is not proof of source comprehension.

Collect agency and enjoyment reports alongside performance. The cognitive-forcing
study [8] is an important warning: users can prefer a system that helps them less.
Use an optional short decision prompt at consequential moments and test whether
it becomes burdensome. Do not require an essay before every move.

Do not optimise tokens, branch count, time in app, supported-claim count or run
completion. Adopt the new model if it improves test choice and understanding at
the same or lower request cost while preserving uncertainty, accessibility and
Stop semantics. Set detailed acceptance thresholds before evaluating, rather than
choosing favourable thresholds after seeing results.

## J. Open research questions

- Does an optional human-first explanation reduce anchoring enough to justify the
  added effort? Does the answer differ by domain expertise?
- Does a comparison board outperform the existing map for this owner's actual
  tasks, rather than an artificial map-reading exercise?
- What minimum passage/provenance is needed before a cited URL becomes useful
  evidence, and what does the actual bounded provider tool expose reliably?
- Should model confidence be hidden by default, and does removing its selection
  shortcut improve which uncertainties get investigated?
- Which combination of unknown, conflicting and context-limited material makes
  people revise an explanation rather than merely collect more sources?
- How much evidence review should be prompted, and when does prompting reduce
  voluntary engagement or disadvantage users who find it more demanding?
- How should reused evidence be scoped when a source page changes or a question's
  population/timeframe changes?
- Can a four/five-request round provide enough independent material for useful
  comparison? Which questions should instead be narrowed or parked?
- What local reflection remains useful after an all-dead-end round, when Learn is
  correctly unavailable because no source cards exist?

These are experiments and capability checks, not matters to settle with a more
confident prompt. The next investment should make thinking and evidence more
inspectable; the existing map can then become a better view of a better model.

## Sources and inspection scope

Foundational evidence supports mechanisms, not a claim that this proposed product
will improve learning. Several publisher pages were unavailable; abstracts were
read through scholarly metadata where indicated. No numerical confidence score
for the proposed design is inferred from these sources.

1. Lazonder & Harmsen (2016), *Meta-Analysis of Inquiry-Based Learning: Effects of
   Guidance*. [DOI](https://doi.org/10.3102/0034654315627366). Publisher-deposited
   abstract inspected through Crossref; 72 studies. Supports guided inquiry, not
   a specific AI interface.
2. Sinha & Kapur (2021), *When Problem Solving Followed by Instruction Works:
   Evidence for Productive Failure*. [DOI](https://doi.org/10.3102/00346543211019105).
   Publisher-deposited abstract inspected; 53 studies/166 comparisons, with
   important implementation and learner differences.
3. Nesbit & Adesope (2006), *Learning With Concept and Knowledge Maps: A
   Meta-Analysis*. [DOI](https://doi.org/10.3102/00346543076003413).
   Publisher-deposited abstract inspected; heterogeneous instructional contexts.
4. Karpicke & Blunt (2011), *Retrieval Practice Produces More Learning Than
   Elaborative Studying With Concept Mapping*.
   [PubMed](https://pubmed.ncbi.nlm.nih.gov/21252317/). Indexed abstract inspected
   through Europe PMC. A particular experiment, not a universal anti-map result.
5. Kirsh & Maglio (1994), *On Distinguishing Epistemic from Pragmatic Action*.
   [DOI](https://doi.org/10.1207/s15516709cog1804_1). Publisher-deposited abstract
   inspected. Tetris is the studied task; research-workbench transfer is proposed.
6. Ryan, Rigby & Przybylski (2006), *The Motivational Pull of Video Games: A
   Self-Determination Theory Approach*.
   [Author-hosted full paper](https://selfdeterminationtheory.org/SDT/documents/2006_RyanRigbyPrzybylski_MandE.pdf).
   Full text inspected, including limitations; autonomy/competence findings concern
   games, not proof of learning in AIcontrol.
7. Scardamalia & Bereiter (2006), *Knowledge Building: Theory, Pedagogy, and
   Technology*. [Author-hosted chapter](https://ikit.org/fulltext/2006_KBTheory.pdf).
   Full chapter inspected; a theoretical/pedagogical framework with deployed-tool
   examples, not a trial of this design.
8. Buçinca, Malaya & Gajos (2021), *To Trust or to Think: Cognitive Forcing Functions
   Can Reduce Overreliance on AI in AI-assisted Decision-making*.
   [Full paper](https://arxiv.org/abs/2102.09692),
   [DOI](https://doi.org/10.1145/3449287). Full text inspected. Single non-critical
   decision task; performance/usability and individual-difference limitations matter.
9. Matiasz et al. (2018), *ResearchMaps.org for Integrating and Planning Research*.
   [Full paper](https://pmc.ncbi.nlm.nih.gov/articles/PMC5933701/).
   Full text inspected through Europe PMC. A domain-specific representation/tool
   paper, not evidence that a generic graph makes AI research accurate.
10. 3ie, [Evidence gap maps](https://www.3ieimpact.org/evidence-hub/evidence-gap-maps).
    Methods overview inspected. Systematic identification and scope are essential;
    two web searches do not constitute that method.
11. Obsidian, [Canvas](https://obsidian.md/canvas). Official feature documentation
    inspected. Product precedent for durable arrangement and editable objects.
12. Kialo, [Kialo Edu](https://www.kialo-edu.com/). Official product page inspected.
    Product precedent for explicit pro/con structure; marketing claims and
    testimonials are not treated as independent efficacy evidence.
13. Mobius Digital, [Outer Wilds](https://www.mobiusdigitalgames.com/outer-wilds.html).
    Official developer description inspected. Design analogy for curiosity,
    revisitation and tools; no claim of an educational efficacy trial.
14. Amershi et al. (2019), [Guidelines for Human-AI Interaction](https://www.microsoft.com/en-us/research/publication/guidelines-for-human-ai-interaction/).
    Publication overview inspected. Relevant to making capabilities, correction and
    uncertainty intelligible, not a guarantee of this proposal's success.
15. W3C, [Keyboard](https://www.w3.org/WAI/WCAG22/Understanding/keyboard.html),
    [Non-text content](https://www.w3.org/WAI/WCAG22/Understanding/non-text-content.html),
    [Target size](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html).
    Understanding documents inspected as accessibility guidance.

Code anchors for the diagnosis at the reviewed commit:

- [Evidence summaries and citations](https://github.com/efrgdgr0024345/AIcontrol/blob/73e85b89a0a75f6c293f6b33de789754c8877779/src/Investigation.php#L385).
- [Reconciliation checks](https://github.com/efrgdgr0024345/AIcontrol/blob/73e85b89a0a75f6c293f6b33de789754c8877779/src/Investigation.php#L404).
- [Irreversible run selection](https://github.com/efrgdgr0024345/AIcontrol/blob/73e85b89a0a75f6c293f6b33de789754c8877779/src/Investigation.php#L214).
- [Map objects and local state](https://github.com/efrgdgr0024345/AIcontrol/blob/73e85b89a0a75f6c293f6b33de789754c8877779/assets/panel.js#L448).
