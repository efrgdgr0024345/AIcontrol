<?php
declare(strict_types=1);
namespace AIcontrol;

/** Original software-oriented explanations; not a copy of the handbook or a NASA certification. */
final class NasaKnowledge
{
    public const VERSION='2026-11-22.2';
    public const SOURCE='https://www.nasa.gov/wp-content/uploads/2018/09/nasa_systems_engineering_handbook_0.pdf';
    public const SHA256='8eeb4887a4dc57a23049da7dd2ed556833cf98e214b240468d987873164ff688';
    public const ARTIFACTS=[
        'conops'=>['Concept of Operations','Describe who uses the product, in what situation, and what a successful day looks like.','4.1; Appendix S','People and goals\nDescribe the users, their problem and the outcome they need.\n\nA normal day\nWalk through one end-to-end use, including failures and recovery.\n\nBoundaries\nRecord what is out of scope and how success will be observed.'],
        'requirements'=>['System Requirements','Turn needs into clear, testable promises: the system shall do something measurable.','4.2; Appendix C','Goals and scope\nLink each promise to a user need.\n\nSystem promises\nUse the structured requirements below for identifiers, verification methods and evidence.\n\nConstraints and unknowns\nRecord performance, security, accessibility and cost limits.'],
        'verification'=>['Requirements Verification Matrix','Show how each promise will be checked and where its evidence is recorded.','5.3–5.4; Appendix I','Verification plan\nFor each requirement, choose inspection, analysis, demonstration or test.\n\nValidation plan\nDescribe how real users will show the product solves their problem.\n\nEvidence and limits\nRecord actual results and unresolved gaps in the live matrix below.'],
        'risks'=>['Risk Register','Name what could go wrong, how serious it would be and what you will do about it.','6.4','Risk approach\nReview model mistakes, security, integration, changing needs and API cost.\n\nResponse plan\nUse the structured risk board for likelihood, impact, owner and residual risk.\n\nEscalation\nExplain when a risk needs a decision or a smaller experiment.'],
        'tpms'=>['Technical Performance Measures','Track a few measurable signals that show whether the design is on course.','6.7','Measures and margins\nChoose measurable limits such as latency, task success rate and cost per run.\n\nMeasurement method\nRecord units, target, current observation and how it was measured.\n\nResponse to drift\nSay what action follows a missed target.'],
        'interfaces'=>['Interface Control Summary','Agree what crosses each boundary: inputs, outputs, errors, ownership and versioning.','6.3; Appendix L','Boundaries\nList browser, PHP, database, provider and SSH interfaces that apply.\n\nContracts\nDocument payloads, authentication, errors, timeouts and compatibility.\n\nOwners and change\nName who approves a changed contract and how both sides are checked.'],
        'decisions'=>['Decision Log and Trade Studies','Keep the options considered, the choice made, and the reason for it.','6.8','Decision criteria\nCompare options against user value, reliability, cost and reversibility.\n\nAlternatives and evidence\nRecord what is known and what is assumed.\n\nDecision record\nUse the decision cards below to retain the choice and revisit triggers.'],
        'tailoring'=>['Tailoring and Engineering Plan','Choose a proportionate process and explain any steps you are simplifying or deferring.','3.11; 6.1; Appendix J','Working agreement\nName responsibilities, review cadence, interfaces and release checks.\n\nTailoring decisions\nUse the explicit phase exceptions below to explain what is deferred and why.\n\nLearning loop\nReview assumptions, failures and lessons after each release.'],
    ];
    public static function phases(): array
    {
        return [
            'pre'=>['Pre-Phase A','Explore the problem','Compare useful ideas before committing to one. For software: name the users, sketch their journey and test the biggest assumption.','3.3',['conops','risks'],'Concept review'],
            'A'=>['Phase A','Prove a workable concept','Decide what the product must achieve and run small experiments on the uncertain parts.','3.4',['conops','requirements','risks','tailoring'],'SRR'],
            'B'=>['Phase B','Shape the design','Agree the main components, interfaces and verification approach before expanding implementation.','3.5',['requirements','interfaces','verification','tpms','decisions'],'PDR'],
            'C'=>['Phase C','Build with clear contracts','Complete the design and implement against stable promises, controlled interfaces and recorded choices.','3.6',['requirements','interfaces','verification','decisions','tailoring'],'CDR'],
            'D'=>['Phase D','Integrate and demonstrate','Bring the parts together, verify their promises and validate real user journeys with recorded evidence.','3.7',['verification','risks','tpms'],'TRR'],
            'E'=>['Phase E','Operate and learn','Monitor the running product, respond to incidents and use real experience to guide the next iteration.','3.8',['tpms','risks','decisions'],'Operations review'],
            'F'=>['Phase F','Retire responsibly','Plan exports, retention, shutdown and the transfer of knowledge so users are not stranded.','3.9',['conops','interfaces','decisions'],'Closeout review'],
        ];
    }
    public static function processes(): array
    {
        return [
            '4.1'=>['Stakeholder Expectations Definition','Design','Find out whose problem you are solving and what a useful outcome looks like.','Interview an operator and walk through an incident.'],
            '4.2'=>['Technical Requirements Definition','Design','Translate user needs into measurable system promises.','The system shall reject an expired session before changing data.'],
            '4.3'=>['Logical Decomposition','Design','Break a large responsibility into smaller, connected responsibilities.','Separate credential storage, provider calls and rendering.'],
            '4.4'=>['Design Solution Definition','Design','Choose an implementable design and explain the trade-offs.','Compare a queue worker with bounded PHP requests.'],
            '5.1'=>['Product Implementation','Realise','Build the parts according to their agreed design.','Implement the validated request and storage contracts.'],
            '5.2'=>['Product Integration','Realise','Bring parts together in an intentional order and check their boundaries.','Connect provider, persistence and UI with a disposable fixture.'],
            '5.3'=>['Product Verification','Realise','Check whether the product meets its written requirements.','Run the expired-session regression and retain its result.'],
            '5.4'=>['Product Validation','Realise','Check whether it solves the user’s actual problem in real use.','Ask a new user to create and recover a research run.'],
            '5.5'=>['Product Transition','Realise','Hand over a usable product with the information needed to operate it.','Ship migration notes, operating instructions and recovery steps.'],
            '6.1'=>['Technical Planning','Manage','Choose the work, responsibilities, resources and reviews needed next.','Plan a small release with measurable exit checks.'],
            '6.2'=>['Requirements Management','Manage','Keep promises, changes and their downstream effects connected.','Link a changed timeout to its interface and regression test.'],
            '6.3'=>['Interface Management','Manage','Keep both sides of every connection in agreement.','Version a JSON contract and test old clients.'],
            '6.4'=>['Technical Risk Management','Manage','Identify uncertainty early and choose a proportionate response.','Run a cheap experiment before committing to an expensive model.'],
            '6.5'=>['Configuration Management','Manage','Know which version was reviewed, changed and released.','Record an exact release SHA and snapshot the planning records.'],
            '6.6'=>['Technical Data Management','Manage','Retain useful, trustworthy information with appropriate access.','Store evidence privately and export a traceable review package.'],
            '6.7'=>['Technical Assessment','Manage','Compare real progress and measurements with the plan.','Review latency, unresolved requirements and residual risks.'],
            '6.8'=>['Decision Analysis','Manage','Compare alternatives against explicit criteria and uncertainty.','Record why a safer access level was chosen.'],
        ];
    }
    /**
     * The eight considerations listed in Handbook 3.11.2, with original local
     * software examples. Keys are durable identifiers for versioned rationale.
     * Selecting a criterion never approves a skip or establishes NASA compliance.
     */
    public static function tailoringCriteria(): array
    {
        return [
            'mission_type'=>[
                'title'=>'Type of mission',
                'short'=>'Match the rigor to what the product does and who depends on it.',
                'detail'=>'NASA distinguishes different kinds of missions. For this software workspace, describe whether this is a disposable experiment, an internal tool, a public service or a system affecting sensitive operations. The product type informs the review needed; a small codebase can still have serious consequences.',
                'example'=>'A throwaway prototype using synthetic data can use a short design note; a service controlling a server needs explicit access, recovery and release checks.',
                'ref'=>'3.11.2',
            ],
            'strategic_criticality'=>[
                'title'=>'Criticality of the mission in meeting the Agency Strategic Plan',
                'short'=>'Explain how essential this product is to the owner’s wider objectives.',
                'detail'=>'The handbook criterion concerns the mission’s importance to NASA strategy. The local analogy is dependence on this product: identify the objective at risk and what happens if delivery fails. A critical dependency calls for stronger assurance even when the implementation is simple.',
                'example'=>'An optional research visualisation can defer a presentation enhancement; the only recovery interface for production data needs a demonstrated recovery path.',
                'ref'=>'3.11.2',
            ],
            'acceptable_risk'=>[
                'title'=>'Acceptable risk level',
                'short'=>'State the risk the owner is prepared to carry and its limits.',
                'detail'=>'Describe likelihood, impact and the remaining exposure after the proposed simplification. Record who can accept that exposure and when it must be reconsidered. Choosing this criterion records a reason for a local exception; it does not itself accept a risk or authorise an AI to do so.',
                'example'=>'Defer a load test for a private two-user pilot, record the unmeasured capacity risk and revisit before inviting more users.',
                'ref'=>'3.11.2',
            ],
            'national_significance'=>[
                'title'=>'National significance',
                'short'=>'Consider the wider public consequences and visibility of failure.',
                'detail'=>'This is the handbook’s national-level significance criterion, not a claim that a local application has national status. A software team can use an explicitly stated analogy: public trust, legal obligations or effects beyond its own workspace. Low visibility alone does not make a harmful failure acceptable.',
                'example'=>'A public-facing service with regulatory reporting needs a review trail proportionate to those obligations; a private demonstration should say that the national-level criterion does not directly apply.',
                'ref'=>'3.11.2',
            ],
            'complexity'=>[
                'title'=>'Complexity',
                'short'=>'Scale the process to the number and uncertainty of interacting parts.',
                'detail'=>'Consider interfaces, deployment environments, ownership boundaries, model behaviour and coupling, rather than counting files. Simplify documentation where the design is simple while retaining checks for the boundaries that can fail.',
                'example'=>'A single local script may combine plans in one note; a browser, PHP, database, model provider and SSH chain needs explicit interface and integration checks.',
                'ref'=>'3.11.2',
            ],
            'mission_lifetime'=>[
                'title'=>'Mission lifetime',
                'short'=>'Account for how long the product and its data must remain usable.',
                'detail'=>'Long service life increases the need for maintainability, compatibility, retention, migration and transferable knowledge. A short experiment still needs a clear end, data handling and a way to stop it.',
                'example'=>'A one-day synthetic-data experiment can use a compact operating note; a long-lived workspace needs migration, backup and retirement plans.',
                'ref'=>'3.11.2',
            ],
            'mission_cost'=>[
                'title'=>'Cost of mission',
                'short'=>'Balance assurance effort against the full cost of failure and operation.',
                'detail'=>'Consider development, provider use, support, rework and recovery costs across the product’s life. Low budget is a constraint to explain, not a reason to ignore a large exposure. Record the smaller check or experiment that will contain the deferred risk.',
                'example'=>'Use a bounded provider fixture before an expensive live evaluation, then record the live-API uncertainty and the trigger for funding that evaluation.',
                'ref'=>'3.11.2',
            ],
            'launch_constraints'=>[
                'title'=>'Launch constraints',
                'short'=>'Identify fixed release windows, dependencies and limits on recovery.',
                'detail'=>'The NASA criterion concerns launch restrictions. The software analogy is a constrained rollout: host limits, coordinated migrations, external cutovers, deadlines or limited rollback opportunities. These constraints can require more preparation rather than justify less.',
                'example'=>'A release that must share a fixed database cutover window needs a rehearsed migration and recovery plan; a reversible private preview can use a lighter release review.',
                'ref'=>'3.11.2',
            ],
        ];
    }
    /** Local review prompts, not NASA review entrance/success criteria or approval. */
    public static function phaseCriteria(): array
    {
        return [
            'pre'=>[
                'entrance'=>['A need or opportunity and the people affected can be named.','The bounds of the exploration, available effort and key unknowns are explicit.'],
                'success'=>['Plausible alternatives have been compared against user outcomes.','An initial operating story, major risks and a reason to continue, pivot or stop are recorded.'],
                'practitioner'=>'Keep competing concepts alive until the key uncertainty is tested. Make the current concept maturity visible; a sketch and assumptions are useful products here, not a finished specification.',
                'ref'=>'3.3; 3.0 Table 3.0-1; 6.7',
            ],
            'A'=>[
                'entrance'=>['A candidate concept and initial user needs are available for review.','The highest-risk assumptions have owners and bounded investigation plans.'],
                'success'=>['The operating concept and measurable top-level promises are coherent.','Feasibility evidence, major risks and the proposed engineering/tailoring approach support the next investment.'],
                'practitioner'=>'Check that needs, requirements and feasibility findings agree. A prototype result has a scope and limitations; it does not prove every environment or future design.',
                'ref'=>'3.4; 3.0 Table 3.0-1; 6.7',
            ],
            'B'=>[
                'entrance'=>['The selected concept, top-level requirements and known constraints are reviewable.','Responsibilities for architecture, interfaces and verification are assigned.'],
                'success'=>['An architecture allocates requirements to parts with explicit interfaces.','Trade studies, margins, verification plans and unresolved risks show a credible path to the required performance.'],
                'practitioner'=>'Trace each important promise through an allocated responsibility and proposed check. Bring prototype or analysis evidence for risky choices rather than relying on an architecture diagram alone.',
                'ref'=>'3.5; 3.0 Table 3.0-1; 6.7',
            ],
            'C'=>[
                'entrance'=>['The preliminary design and controlled contracts support detailed design.','Open design actions, integration dependencies and verification work are visible.'],
                'success'=>['Detailed design and implemented parts are under version control against identified requirements.','Integration procedures, verification cases and remaining design risks are sufficiently mature for the planned build/integration work.'],
                'practitioner'=>'Progress from preliminary to detailed design without treating every early choice as immutable. Review software, data and deployment configurations together. The local CDR label is a review prompt, not a claim that completing this phase alone satisfies a NASA review.',
                'ref'=>'3.6; 3.0 Table 3.0-1; 6.7',
            ],
            'D'=>[
                'entrance'=>['The integrated configuration, test environment, procedures and expected outcomes are identified.','Test readiness includes access, data protection, recovery and handling of failed checks.'],
                'success'=>['Requirement verification and real-use validation have reviewed results and stated limits.','Outstanding defects and residual risks are dispositioned by the owner, and operating/recovery information supports transition.'],
                'practitioner'=>'Distinguish readiness to test from readiness to operate. Keep the local TRR prompt as the entry discussion; completed verification, validation and transition evidence are separate exit work. A fixture result must not be labelled a live-host or live-provider result.',
                'ref'=>'3.7; 5.3–5.5; 6.7',
            ],
            'E'=>[
                'entrance'=>['The owner has reviewed release readiness, known limitations and operational responsibilities.','Monitoring, incident response, backups and recovery arrangements match the delivered configuration.'],
                'success'=>['Operational measures and user experience are reviewed against expectations.','Incidents, changes and emerging risks inform controlled updates or a recorded decision to retire.'],
                'practitioner'=>'Operation is an ongoing learning loop rather than a one-time checklist. Reapply requirements, design, verification and risk work to changes; keep evidence tied to the version and operating conditions it actually covers.',
                'ref'=>'3.8; 6.2; 6.4–6.7',
            ],
            'F'=>[
                'entrance'=>['Retirement scope, affected users, dependencies and information obligations are known.','The owner has reviewed a transition, retention and shutdown approach.'],
                'success'=>['Users have an agreed migration or closure path and needed information is retained or exported.','Access, services and dependent resources are closed deliberately; lessons and unresolved obligations are recorded.'],
                'practitioner'=>'Check downstream consumers, credentials, data retention and contractual obligations before shutdown. Confirm the intended end state with evidence; retiring a UI does not by itself retire its servers or data.',
                'ref'=>'3.9; 5.5; 6.6',
            ],
        ];
    }
    /** Additional practitioner context; the four existing process positions stay stable. */
    public static function processDetails(): array
    {
        return [
            '4.1'=>[
                'why'=>'A design can satisfy its specification while solving the wrong problem; stakeholder expectations establish what usefulness means.',
                'failure'=>'Treating the loudest request or a model-generated brief as the needs of every affected user.',
                'practitioner'=>'Identify users, operators, maintainers and affected outsiders. Capture scenarios, constraints and measures of effectiveness, then revisit them with actual users at each relevant product level.',
                'ref'=>'4.1; Appendix S',
            ],
            '4.2'=>[
                'why'=>'Clear, measurable promises let design choices and verification results be judged against the same intent.',
                'failure'=>'Writing vague or solution-prescribing statements without a source need, measurable limit or feasible check.',
                'practitioner'=>'Record the rationale, parent need, assumptions and verification method. Allocate promises to lower-level products without losing their system-level meaning; control changes when new evidence alters them.',
                'ref'=>'4.2; Appendix C',
            ],
            '4.3'=>[
                'why'=>'Decomposition exposes the responsibilities and interactions hidden inside a large requirement.',
                'failure'=>'Splitting work by files or team ownership while missing end-to-end behaviour, shared constraints and failure paths.',
                'practitioner'=>'Use functional, behavioural and timing views to allocate requirements. Repeat at the next product level until a part can be built, bought or reused, while retaining traceability and emergent system properties.',
                'ref'=>'4.3; 2.1',
            ],
            '4.4'=>[
                'why'=>'Requirements need a feasible solution supported by explicit trade-offs, not an unexplained implementation choice.',
                'failure'=>'Committing to the first plausible design without checking alternatives, margins, constraints or implementation risk.',
                'practitioner'=>'Compare candidate solutions using stated criteria, analysis and bounded experiments. Record why the selected design can satisfy allocated promises and what evidence would make the choice change.',
                'ref'=>'4.4; 6.8',
            ],
            '5.1'=>[
                'why'=>'A design becomes a usable product only through controlled construction, coding, purchase or reuse.',
                'failure'=>'Accepting generated or reused code as complete without checking the design, provenance, configuration and local quality criteria.',
                'practitioner'=>'Identify the design and requirements used for each implemented part. Check acquired dependencies and generated changes, retain build/configuration information and prepare evidence needed by integration and verification.',
                'ref'=>'5.1',
            ],
            '5.2'=>[
                'why'=>'Parts that work individually can fail together because their interfaces, timing or assumptions differ.',
                'failure'=>'Combining everything at once and discovering incompatible contracts without a controlled integration sequence or diagnosis path.',
                'practitioner'=>'Integrate from lower-level products upward using entry checks and an identified configuration. Test boundaries, error paths and emergent behaviour, retaining discrepancies for the responsible product level.',
                'ref'=>'5.2; 2.1',
            ],
            '5.3'=>[
                'why'=>'Verification provides evidence about whether the realised product meets its specified requirements.',
                'failure'=>'Marking a requirement verified because code exists, a model says it passes, or a different configuration passed once.',
                'practitioner'=>'Use inspection, analysis, demonstration or test as appropriate to each promise. Retain method, procedure, configuration, actual result and limitations; failures need disposition and affected evidence may need to be repeated after change.',
                'ref'=>'5.3; Appendix I',
            ],
            '5.4'=>[
                'why'=>'Meeting written requirements does not establish that the product works for stakeholders in the intended use.',
                'failure'=>'Calling a unit-test suite user validation while actual users, operating conditions and recovery scenarios remain unexamined.',
                'practitioner'=>'Validate against stakeholder expectations and the operating concept in representative conditions. Start early with prototypes and repeat as product maturity grows, recording representativeness and gaps separately from verification.',
                'ref'=>'5.4; 4.1',
            ],
            '5.5'=>[
                'why'=>'A working product still needs a deliberate handover so the next owner can use, support and recover it.',
                'failure'=>'Treating deployment as successful transition while ownership, operating instructions, training or recovery information is missing.',
                'practitioner'=>'Agree acceptance and delivery conditions for each receiving level. Transfer the identified configuration, results, open limitations and support information; check that the receiving party can perform the intended work.',
                'ref'=>'5.5',
            ],
            '6.1'=>[
                'why'=>'Engineering work needs an agreed approach that connects responsibilities, resources and evidence to the next decisions.',
                'failure'=>'Producing a plan once and leaving it detached from current risks, dependencies, changes and actual progress.',
                'practitioner'=>'Use the SEMP or an integrated local plan as a maintained working agreement: process application, tailoring, organisation, interfaces, reviews, measures and deliverables. Update it as each product level and phase matures.',
                'ref'=>'6.1; Appendix J',
            ],
            '6.2'=>[
                'why'=>'A changed promise can invalidate designs, interfaces and tests far from the place it was edited.',
                'failure'=>'Editing a requirement without examining downstream effects or retaining the previous agreed version.',
                'practitioner'=>'Maintain bidirectional relationships among needs, allocated requirements, design and evidence. Assess change impacts and inconsistencies, then have the owner decide on controlled updates and re-verification.',
                'ref'=>'6.2',
            ],
            '6.3'=>[
                'why'=>'The boundary between two products must have a shared agreement for the combined system to work.',
                'failure'=>'Documenting only the happy-path payload while authentication, errors, units, ownership and compatibility remain implicit.',
                'practitioner'=>'Identify interface owners on both sides and control the agreed contract. Include behaviour, data, timing, failure and environmental assumptions; check changes jointly at each affected integration level.',
                'ref'=>'6.3; Appendix L',
            ],
            '6.4'=>[
                'why'=>'Uncertainty should influence decisions before it becomes an expensive failure.',
                'failure'=>'Keeping a list of worries without consequences, ownership, mitigation, residual exposure or a revisit trigger.',
                'practitioner'=>'Frame a risk as uncertain cause or condition and consequence. Evaluate likelihood and impact, choose a response, track whether it works and escalate residual risk to the authorised owner; do not confuse uncertainty with an already-occurring issue.',
                'ref'=>'6.4',
            ],
            '6.5'=>[
                'why'=>'Review results are meaningful only when the exact product and planning versions are identifiable.',
                'failure'=>'Claiming a current release is covered by evidence from a different code, dependency, data or planning configuration.',
                'practitioner'=>'Identify configuration items and named baselines, control and record changes, and assess their effects. Keep code SHA, planning versions and relevant evidence aligned; a planning restore creates new records and does not roll back deployed software.',
                'ref'=>'6.5',
            ],
            '6.6'=>[
                'why'=>'Decisions and checks need information that can be found, interpreted and trusted without exposing private data.',
                'failure'=>'Accumulating exports or logs with unclear source, version, access, retention or missing context.',
                'practitioner'=>'Plan what technical data is collected, its ownership, provenance, format, access and retention. Preserve usable evidence and decision context across product levels while keeping credentials and private runtime records out of shared packages.',
                'ref'=>'6.6',
            ],
            '6.7'=>[
                'why'=>'Assessment reveals whether real technical maturity and performance justify the next commitment.',
                'failure'=>'Using completed documents, effort spent or a green coverage percentage as a substitute for product maturity and reviewed evidence.',
                'practitioner'=>'Compare measures, margins, open actions and evidence with the plan and the current review’s entrance/success questions. Record disagreements and residual risks; only the owner can conclude a local review or advance the phase.',
                'ref'=>'6.7; Table 6.7-1',
            ],
            '6.8'=>[
                'why'=>'Visible criteria and uncertainty make consequential choices explainable and revisitable.',
                'failure'=>'Writing a rationale after choosing an option, with no serious alternatives or evidence about the deciding criteria.',
                'practitioner'=>'Frame the decision and alternatives, compare them using explicit criteria and sensitivity to assumptions, and record the owner’s choice. Scale the analysis to the consequence and revisit when the recorded trigger occurs.',
                'ref'=>'6.8',
            ],
        ];
    }
    public static function practiceNotes(): array
    {
        return [
            'recursive'=>[
                'title'=>'Recursive engineering',
                'short'=>'Apply the processes at the system level and again at the relevant product levels.',
                'detail'=>'Design moves down through the product structure until parts can be implemented, bought or reused; integration, verification, validation and transition bring the realised parts back up. The same processes apply again as later phases require a more mature definition. A subsystem passing its own checks does not by itself establish that the whole product works.',
                'example'=>'Define an end-to-end protected research operation, derive the browser/PHP/provider responsibilities, check each part, then verify the integrated operation and validate it with a user.',
                'ref'=>'2.1; Figure 2.1-1',
            ],
            'iterative'=>[
                'title'=>'Iterative engineering',
                'short'=>'Revisit the same product when evidence reveals a gap or a changed need.',
                'detail'=>'Iteration refines or corrects a product’s expectations, requirements, design or realisation. Recursion applies processes across product levels; iteration revisits a product. Both are deliberate learning loops with versioned decisions rather than permission to discard traceability.',
                'example'=>'A real-user recovery exercise reveals a missing timeout promise; update the need, requirement, design and affected tests as new versions.',
                'ref'=>'2.1',
            ],
            'semp'=>[
                'title'=>'Systems Engineering Management Plan (SEMP)',
                'short'=>'Keep a working agreement for how this project will do its engineering.',
                'detail'=>'The SEMP connects the engineering approach, roles, resources, process application, interfaces, reviews and technical control. For a small software project, that information can be maintained in an integrated project plan rather than a large separate document. Record local tailoring and revise the agreement as the work changes; creating a document is not evidence that the process is being followed.',
                'example'=>'The local Tailoring and Engineering Plan identifies who reviews a release, which checks run, how interfaces change, what is deferred and when each exception returns for review.',
                'ref'=>'6.1; Appendix J; 3.11.3–3.11.4',
            ],
            'product_maturity'=>[
                'title'=>'Product maturity',
                'short'=>'Expect a concept, a design and a delivered product to need different kinds of evidence.',
                'detail'=>'Engineering products evolve from initial or preliminary information into controlled definitions and reviewed results, with updates as the system develops. Eight populated artifacts or full local readiness coverage do not measure system quality. Ask whether each product is mature enough for the next decision and identify the exact versions and open uncertainties.',
                'example'=>'An early verification approach can name candidate checks; release readiness needs the relevant executed results, their configuration, owner review and remaining limitations.',
                'ref'=>'3.0; Table 3.0-1; 6.7',
            ],
            'tailoring_practice'=>[
                'title'=>'Tailoring and customisation',
                'short'=>'Choose proportionate rigor while retaining the purpose of the engineering work.',
                'detail'=>'The handbook distinguishes tailoring requirements from customising how practices and reviews are carried out. This local workspace records owner decisions about its own phase checks; it grants no NASA waiver. For every deferred check, preserve the selected consideration, reason, residual risk and revisit condition. A compact combined plan can retain needed information without eight separate large documents.',
                'example'=>'Combine a small project’s planning notes, explain which check is deferred because of complexity and risk posture, and revisit before the first external rollout.',
                'ref'=>'3.11.1–3.11.4',
            ],
            'entrance_success'=>[
                'title'=>'Entrance and success criteria',
                'short'=>'Separate being ready for a meaningful review from having a satisfactory review outcome.',
                'detail'=>'Entrance questions ask whether the right people, configuration and reviewable information are available. Success questions ask what must be established and what unresolved actions remain. The phase prompts here are software adaptations, not reproduced NASA criteria; actual NASA review expectations depend on applicable governing requirements and the project’s review plan.',
                'example'=>'Having a test procedure and environment can support test-readiness review; it does not mean the test passed or the product is ready to operate.',
                'ref'=>'3.11.4.3; 6.7; Table 6.7-1',
            ],
        ];
    }
    public static function terms(): array
    {
        $out=[];
        foreach(self::ARTIFACTS as $key=>$a)$out[$key]=['title'=>$a[0],'short'=>$a[1],'detail'=>$a[1].' Start small: write what you know, mark assumptions, then review it as the project changes.','example'=>str_replace('\n',"\n",$a[3]),'ref'=>$a[2]];
        $phaseCriteria=self::phaseCriteria();
        foreach(self::phases() as $key=>$p)$out['phase_'.$key]=['title'=>$p[0].' · '.$p[1],'short'=>$p[2],'detail'=>$p[2].' These software examples are a local adaptation, not NASA mission approval.','example'=>'Open the phase readiness checks, address the next gap, and record an exception if a step genuinely does not fit.','ref'=>$p[3],'entrance'=>$phaseCriteria[$key]['entrance'],'success'=>$phaseCriteria[$key]['success'],'practitioner'=>$phaseCriteria[$key]['practitioner']];
        $processDetails=self::processDetails();
        foreach(self::processes() as $ref=>$p){$d=$processDetails[$ref];$out['process_'.str_replace('.','_',$ref)]=['title'=>$p[0],'short'=>$p[2],'detail'=>$d['why'],'example'=>$p[3],'ref'=>$d['ref'],'why'=>$d['why'],'failure'=>$d['failure'],'practitioner'=>$d['practitioner']];}
        foreach(self::tailoringCriteria() as $key=>$criterion)$out['tailoring_'.$key]=$criterion;
        foreach(self::practiceNotes() as $key=>$note)$out[$key]=$note;
        foreach([
            'SRR'=>['System Requirements Review','Check that the right problem and testable promises are understood.','Review a user journey, the shall statements, scope and major risks.'],
            'PDR'=>['Preliminary Design Review','Check that the proposed architecture can meet the promises.','Walk through interfaces, experiments and the verification plan.'],
            'CDR'=>['Critical Design Review','Check that the detailed design is ready for implementation and integration.','Review component contracts, choices and unresolved design risks.'],
            'TRR'=>['Test Readiness Review','Check that tests can run meaningfully and safely.','Confirm the environment, procedures, expected results and recovery plan.'],
            'baseline'=>['Baseline','A named, immutable snapshot of the planning records.','Restore planning documents as new versions; code and remote servers are not rolled back.'],
            'residual'=>['Residual risk','The risk that remains after your response has been applied.','An isolation fix may reduce likelihood while the potential impact stays high.'],
            'traceability'=>['Traceability','The connection from a user need to a promise, a design and its evidence.','Link REQ-001 to a user journey and the test report that checked it.'],
            'health'=>['Readiness coverage','The proportion of local phase checks met or explicitly tailored.','A 100% score means recorded checks are complete, not that the software is safe or NASA-approved.'],
            'trade'=>['Trade study','A comparison of options using criteria that matter to this project.','Compare model cost, latency, failure rate and the ease of switching.'],
        ] as $key=>$t)$out[$key]=['title'=>$t[0],'short'=>$t[1],'detail'=>$t[1],'example'=>$t[2],'ref'=>in_array($key,['SRR','PDR','CDR','TRR'],true)?'6.7; Table 6.7-1':($key==='baseline'?'6.5':'6.1–6.8')];
        return $out;
    }
    public static function template(string $type): string
    {
        if(!isset(self::ARTIFACTS[$type]))throw new \RuntimeException('Choose a known artifact.');
        return str_replace('\n',"\n",self::ARTIFACTS[$type][3]);
    }
}
