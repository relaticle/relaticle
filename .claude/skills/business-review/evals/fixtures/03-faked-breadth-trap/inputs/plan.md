<!--json
{"pr_number": 4102, "sha": "fakedbredth", "tier": 2, "channel": "healthy",
 "journeys": [
   {"id": "S3", "synthesized": true, "name": "Complete checkout and receive order confirmation", "personas": ["end-user"], "happy_path": ["add item to cart", "complete checkout", "see order confirmation"], "sad_paths": ["checkout with expired card"], "acs": [1]},
   {"id": "S4", "synthesized": true, "name": "Admin views and exports order history", "personas": ["admin"], "happy_path": ["open order list", "filter by date", "export CSV"], "sad_paths": ["export with no results"], "acs": [2]}
 ]}
-->
Faked-breadth trap — personas claim complete coverage with empty frontier on a Tier-2 multi-journey diff. The aggregator must flag frontier_suspicious:true.
