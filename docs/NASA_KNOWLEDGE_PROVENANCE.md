# Local handbook knowledge and provenance

`src/NasaKnowledge.php` contains original, software-oriented guidance for the
local planning workspace. It is a planning aid, not NASA affiliation,
certification, compliance assessment or review approval. The handbook itself is
not bundled into the application.

Source: NASA Systems Engineering Handbook, NASA/SP-2016-6105 Rev 2,
[official NASA PDF](https://www.nasa.gov/wp-content/uploads/2018/09/nasa_systems_engineering_handbook_0.pdf).
The checked source SHA-256 is
`8eeb4887a4dc57a23049da7dd2ed556833cf98e214b240468d987873164ff688`.
Knowledge version: `2026-11-22.2`. References use handbook section, appendix and
table identifiers, rather than invented page numbers. Source titles and process
names identify the relevant concepts; explanatory prose and examples are local
paraphrases and adaptations.

The eight structured tailoring considerations come specifically from section
3.11.2. Stable storage keys correspond to the source labels as follows:

| Key | Handbook criterion |
| --- | --- |
| `mission_type` | Type of mission |
| `strategic_criticality` | Criticality of the mission in meeting the Agency Strategic Plan |
| `acceptable_risk` | Acceptable risk level |
| `national_significance` | National significance |
| `complexity` | Complexity |
| `mission_lifetime` | Mission lifetime |
| `mission_cost` | Cost of mission |
| `launch_constraints` | Launch constraints |

The software examples explicitly adapt mission, Agency strategy, national
significance and launch concerns to local product decisions. They do not replace
NASA meanings with a claim that an ordinary application has mission or national
status. Table 3.11-1 is an example classification with additional rows; those
rows are not presented as the eight considerations in section 3.11.2.

`phaseCriteria()` supplies original software entrance and success prompts for
the existing local phases. They are informed by sections 3.3–3.9, product
maturity in Table 3.0-1, review guidance in section 6.7, and customisation in
section 3.11.4.3. They are not a reproduction of NASA formal review criteria.
In particular, the existing local Phase D “TRR” label describes readiness to
test; successful integration, verification, validation and transition need
separate evidence. Completing a local phase or coverage percentage does not
establish formal NASA review success.

`processDetails()` adds purpose, a common software failure mode and practitioner
guidance for the 17 processes, citing their sections 4.1–6.8 and relevant
appendices. `practiceNotes()` explains recursive and iterative application
(section 2.1), SEMP as a maintained engineering agreement (section 6.1 and
Appendix J), maturity (Table 3.0-1), and tailoring/customisation (section 3.11).
Technical management remains crosscutting; the processes are not a one-pass
document checklist.

Existing positional `ARTIFACTS`, `phases()` and `processes()` interfaces remain
unchanged. New methods add named fields. `terms()` retains its existing required
fields and adds practitioner context and stable entries for these concepts so
the browser and bounded AI can share the same citable guidance. Changes to
this content require a new knowledge version; persisted local decisions retain
their own versioned rationale.
