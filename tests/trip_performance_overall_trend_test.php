<?php
if(PHP_SAPI!=='cli')exit;
ob_start();require __DIR__.'/trip_performance_on_time_score_test.php';ob_end_clean();$checks=0;
perf_check(performance_delivery_kpis($pdo)['overall_score_trend']===[],'Submitted evaluations do not populate the final score trend');
$pdo->exec("UPDATE trip_performance_evaluations SET status='Finalized',finalized_by=1,finalized_at='2026-01-20 12:00:00+08' WHERE trip_id='T-PERF'");
$pdo->exec("UPDATE trip_performance_evaluations SET status='Finalized',finalized_by=1,finalized_at='2026-01-19 12:00:00+08' WHERE trip_id='T-SCORE-2'");
$trend=performance_delivery_kpis($pdo)['overall_score_trend'];
perf_check(array_column($trend,'trip_id')===['T-SCORE-2','T-PERF'],'Final approval date orders points chronologically');
perf_check(array_column($trend,'score')===[70.0,80.0],'Stored overall scores plotted, not On-Time criterion scores');
$pdo->exec("UPDATE trip_performance_evaluations SET overall_score=0 WHERE trip_id='T-SCORE-2'");
perf_check(performance_delivery_kpis($pdo)['overall_score_trend'][0]['score']===0.0,'Real zero score remains visible');
$pdo->prepare("UPDATE reservations SET cargo_type='DEVELOPMENT TEST simulated score' WHERE id=?")->execute([$res]);
perf_check(count(performance_delivery_kpis($pdo)['overall_score_trend'])===1,'Simulations retain existing exclusion from operational analytics');
echo "$checks overall performance trend checks passed using temporary tables only.\n";
