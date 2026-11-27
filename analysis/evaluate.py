"""Causal synthetic evaluation of the production PHP engine; no API credentials or server needed."""
import argparse
import csv
import hashlib
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path
from statistics import mean, median

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'simulator'))
from commutelive_sim.engine import Route, World
from commutelive_sim.output import Logs
from commutelive_sim.runner import select_fleet, validate


def metrics(errors):
    if not errors:
        return {'n': 0, 'mae_seconds': None, 'median_absolute_error_seconds': None, 'within_60_percent': None}
    absolute = [abs(value) for value in errors]
    return {'n': len(errors), 'mae_seconds': mean(absolute), 'median_absolute_error_seconds': median(absolute),
            'within_60_percent': 100 * sum(value <= 60 for value in absolute) / len(absolute)}


def evaluate(directory, seed=42, vehicles=5, duration=1200):
    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True)
    snapshot = json.loads(subprocess.check_output(['php', str(ROOT / 'analysis/replay.php'), '--snapshot'], cwd=ROOT, text=True))
    snapshot['evaluation'] = {'duration_seconds': duration, 'seed': seed}
    results, gates = [], {}
    normal_traffic = []
    for name in ('normal', 'rush_hour', 'incident', 'sparse'):
        case = directory / name
        case.mkdir(parents=True, exist_ok=True)
        scenario = json.loads((ROOT / 'data/scenarios' / f'{name}.json').read_text(encoding='utf-8'))
        parameters = snapshot['parameters']
        validate(parameters, scenario)
        assignments = select_fleet(snapshot['assignments'], vehicles, scenario)
        routes = {item['data']['code']: Route(item['data'], item['segments'], parameters['geometry_tolerance_m']) for item in snapshot['routes']}
        world = World(routes, assignments, parameters, scenario, seed)
        logs = Logs(case)
        try:
            for tick in range(0, duration + 1):
                for event in world.advance(tick):
                    logs.event(event)
        finally:
            logs.close()
        (case / 'routes.json').write_text(json.dumps(snapshot, indent=2), encoding='utf-8')
        subprocess.run(['php', str(ROOT / 'analysis/replay.php'), str(case.resolve())], cwd=ROOT, check=True)
        with (case / 'arrivals.csv').open(encoding='utf-8') as handle:
            truth = {(r['vehicle'], r['stop']): datetime.fromisoformat(r['actual_arrival_at']) for r in csv.DictReader(handle)}
        replay = json.loads((case / 'predictions.json').read_text(encoding='utf-8'))
        paired = [(p, truth[(p['vehicle'], p['stop'])]) for p in replay['predictions']
                  if (p['vehicle'], p['stop']) in truth and datetime.fromisoformat(p['predicted_at']) < truth[(p['vehicle'], p['stop'])]]
        # Each polling prediction is a sample; both methods use the identical paired sample set.
        for method, field in (('segment_blend_v1', 'predicted_arrival_at'), ('naive_fixed_speed', 'naive_arrival_at')):
            errors = [(datetime.fromisoformat(p[field]) - actual).total_seconds() for p, actual in paired]
            results.append({'scenario': name, 'method': method, **metrics(errors),
                'censored_predictions': len(replay['predictions']) - len(paired), 'vehicles': len(assignments), 'seed': seed, 'is_simulated': True})
        if name == 'incident':
            incident = world.incident
            red = [s['elapsed_seconds'] for s in replay['traffic'] if s['sequence'] == incident['segment_sequence']
                   and s['route_code'] == incident['route_code'] and s['elapsed_seconds'] >= incident['start_seconds'] and s['status'] == 'red']
            delay = min(red) - incident['start_seconds'] if red else None
            gates['incident_red_delay_seconds'] = delay
            gates['incident_red_within_120_seconds'] = delay is not None and delay <= 120
            control = next((s for s in normal_traffic if s['elapsed_seconds'] == min(red)
                            and s['sequence'] == incident['segment_sequence'] and s['route_code'] == incident['route_code']), None) if red else None
            gates['normal_control_status_at_incident_red'] = control['status'] if control else None
            gates['incident_changes_control_to_red'] = control is not None and control['status'] in ('green', 'amber')
        if name == 'normal':
            normal_traffic = replay['traffic']
            series = {}
            for p in replay['predictions']:
                series.setdefault((p['vehicle'], p['stop']), []).append((datetime.fromisoformat(p['predicted_arrival_at']) - datetime.fromisoformat(p['predicted_at'])).total_seconds())
            gates['normal_has_countdown'] = any(len(s) >= 3 and s[-1] < s[0] for s in series.values())
            gates['gps_matched_predictions'] = sum(p['gps_arrival_at'] is not None for p in replay['predictions'])
    if not all(r['n'] > 0 for r in results):
        raise RuntimeError('At least one scenario has no evaluable arrivals; increase the horizon.')
    if not gates['incident_red_within_120_seconds'] or not gates['incident_changes_control_to_red'] or not gates['normal_has_countdown']:
        raise RuntimeError(f'Phase gates failed: {gates}')
    fingerprint = hashlib.sha256()
    for path in sorted((ROOT / 'backend/app/Services').glob('*.php')):
        fingerprint.update(path.name.encode())
        fingerprint.update(path.read_bytes())
    report = {'is_simulated': True, 'dataset': 'fictional evaluation line; real pilot validation pending', 'seed': seed,
        'duration_seconds': duration, 'sampling': 'Every 15 simulated seconds, shared PHP arrivals controller; paired predictions only; unmatched outcomes censored',
        'history': 'Cold-start mode defaults, no future samples or truth used by predictor',
        'engine_sha256': fingerprint.hexdigest(), 'gates': gates, 'results': results}
    (directory / 'results.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    with (directory / 'results.csv').open('w', newline='', encoding='utf-8') as handle:
        writer = csv.DictWriter(handle, fieldnames=list(results[0]))
        writer.writeheader()
        writer.writerows(results)
    return report


def print_table(report):
    print('SIMULATED DATA - fictional evaluation route; not measured road performance')
    print(f"{'Scenario':<12} {'Method':<20} {'N':>5} {'MAE (s)':>10} {'Median (s)':>12} {'+/-60s (%)':>12}")
    for r in report['results']:
        print(f"{r['scenario']:<12} {r['method']:<20} {r['n']:>5} {r['mae_seconds']:>10.2f} {r['median_absolute_error_seconds']:>12.2f} {r['within_60_percent']:>12.2f}")
    print(json.dumps(report['gates'], indent=2))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'analysis/output')
    parser.add_argument('--seed', type=int, default=42)
    args = parser.parse_args()
    print_table(evaluate(args.output, seed=args.seed))
