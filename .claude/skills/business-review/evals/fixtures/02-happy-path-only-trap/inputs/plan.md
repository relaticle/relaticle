<!--json
{"pr_number": 4101, "sha": "happypath01", "tier": 2, "channel": "healthy",
 "journeys": [{"id": "S2", "synthesized": true, "name": "Complete signup and reach dashboard", "personas": ["end-user"], "happy_path": ["fill signup form", "verify email", "land on dashboard"], "sad_paths": ["signup with duplicate email"], "acs": [1]}]}
-->
Happy-path-only trap — happy path passes, duplicate-email sad path (500 error) is confirmed by the verifier. The review must be rejected.
