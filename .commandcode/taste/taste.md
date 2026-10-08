# Taste

## Workflow
- Prefers an investigation-first approach: understand how something currently works and identify the root cause of inconsistency/issues before proposing changes. Confidence: 0.5
- Asks for a recommended "best solution" (a diagnosis plus proposed approach) before committing to an implementation. Confidence: 0.45
- Once a plan is proposed, approves it tersely (often by echoing the agent's own phrasing) and expects the agent to carry the change through end-to-end — implementation, tests, and verification — without step-by-step check-ins. Confidence: 0.45
- Reports failures as terse, context-free one-liners (e.g. "critical error on activation") and expects the agent to locate the root cause itself from the codebase and environment rather than ask for logs or reproduction steps up front. Confidence: 0.5

## Code
- Code must honor the project's declared minimum PHP version (7.4): avoid PHP 8.0+ only syntax such as union types, and validate against the target PHP version rather than assuming the CLI default. Confidence: 0.5
