# On-Time performance display

The Trip Performance graph, adjacent KPI, Driver Performance card and profile now show the average **numeric On-Time Delivery criterion score** from qualifying finalized evaluations. A finalized score of 80 displays 80/100 even if the warehouse assessment says On time. Scores of 80 and 70 average to 75/100. A real zero counts; null scores and provisional evaluations do not.

This criterion average is distinct from the overall weighted performance score. Existing delivery eligibility, simulation exclusions, scoring weights, saved evaluations, and assessment verification remain unchanged. The internal binary delivery-rate metric remains available but is no longer substituted for the numeric score in these performance views.

Verification: 5 isolated score aggregation checks, delivery KPI tests (16), document assessment tests (14), Dispatcher rendering checks (57), PHP/JavaScript syntax checks and a read-only live browser check of the graph, KPI, tooltip, numeric axis and Danilo's card. At verification, TRP-024's finalized On-Time criterion was 80/100 and its overall score remained unchanged. Temporary authenticated sessions were removed.
