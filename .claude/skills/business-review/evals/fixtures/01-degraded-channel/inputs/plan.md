<!--json
{"pr_number": 196, "sha": "deadbeef00", "tier": 2, "channel": "degraded",
 "journeys": [{"id": "S1", "personas": ["end-user"], "happy_path": ["checkout flow completes"], "sad_paths": ["payment fails at confirmation step"], "acs": [1]}]}
-->
Degraded-channel fixture — browser never worked. This run must emit label:blocked and suppress all verdict labels.
