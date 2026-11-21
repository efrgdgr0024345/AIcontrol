<?php
declare(strict_types=1);
namespace AIcontrol;

/** Original software-oriented explanations; not a copy of the handbook or a NASA certification. */
final class NasaKnowledge
{
    public const VERSION='2026-11-22.1';
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
    public static function terms(): array
    {
        $out=[];
        foreach(self::ARTIFACTS as $key=>$a)$out[$key]=['title'=>$a[0],'short'=>$a[1],'detail'=>$a[1].' Start small: write what you know, mark assumptions, then review it as the project changes.','example'=>str_replace('\n',"\n",$a[3]),'ref'=>$a[2]];
        foreach(self::phases() as $key=>$p)$out['phase_'.$key]=['title'=>$p[0].' · '.$p[1],'short'=>$p[2],'detail'=>$p[2].' These software examples are a local adaptation, not NASA mission approval.','example'=>'Open the phase readiness checks, address the next gap, and record an exception if a step genuinely does not fit.','ref'=>$p[3]];
        foreach(self::processes() as $ref=>$p)$out['process_'.str_replace('.','_',$ref)]=['title'=>$p[0],'short'=>$p[2],'detail'=>$p[2],'example'=>$p[3],'ref'=>$ref];
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
