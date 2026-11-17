#!/usr/bin/env python3
"""Exercise Craft retry locks against a disposable, Unix-socket-only PostgreSQL."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time


BASELINE = "d2a1e030ce0a2309c9756634ff86125a9de017c8"
ROOT = Path(__file__).resolve().parents[2]
FIXTURE = Path(__file__).with_name("fixture.php")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--autoload", type=Path, default=ROOT / "vendor/autoload.php")
    parser.add_argument("--baseline-ref", default=BASELINE)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    pg = Path(subprocess.check_output(["pg_config", "--bindir"], text=True).strip())
    php = shutil.which("php")
    if not php or not args.autoload.is_file():
        parser.error("PHP and installed Composer dependencies are required")
    tmp = Path(tempfile.mkdtemp(prefix="queue-pg-", dir="/tmp"))
    socket = tmp / "socket"
    socket.mkdir()
    actor = None
    report = {"baseline_ref": args.baseline_ref, "cases": []}
    baseline_module = tmp / "baseline.php"
    try:
        baseline_module.write_bytes(subprocess.check_output([
            "git", "-C", str(ROOT), "show", args.baseline_ref + ":src/Module.php",
        ]))
        subprocess.run([
            str(pg / "initdb"), "-D", str(tmp / "data"), "-A", "trust", "-U", "queue_test", "--no-locale",
        ], check=True, stdout=subprocess.DEVNULL)
        subprocess.run([
            str(pg / "pg_ctl"), "-D", str(tmp / "data"), "-l", str(tmp / "postgres.log"),
            "-o", f"-h '' -k {socket} -p 55439", "-w", "start",
        ], check=True, stdout=subprocess.DEVNULL)
        env = dict(os.environ, QUEUE_TEST_SOCKET=str(socket),
                   QUEUE_TEST_SIGNAL=str(tmp / "signal.json"),
                   QUEUE_TEST_AUTOLOAD=str(args.autoload.resolve()))
        command = [php, "-d", "error_reporting=24575", str(FIXTURE)]

        def run(mode):
            result = subprocess.run(command + [mode], env=env, capture_output=True, text=True, timeout=20)
            if result.returncode:
                raise RuntimeError(result.stdout + result.stderr)
            return json.loads(result.stdout) if result.stdout.strip() else None

        # Both methods must fail before the fix and permit reserve() after it.
        for baseline in (True, False):
            for operation in ("retry", "retryAll"):
                env["QUEUE_TEST_MODULE"] = str(baseline_module if baseline else ROOT / "src/Module.php")
                env["QUEUE_TEST_DELAY_US"] = "6000000"
                signal = tmp / "signal.json"
                signal.unlink(missing_ok=True)
                run("seed")
                actor = subprocess.Popen(command + [operation], env=env,
                                         stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                deadline = time.monotonic() + 15
                while not signal.exists():
                    if actor.poll() is not None:
                        raise RuntimeError(str(actor.communicate()))
                    if time.monotonic() > deadline:
                        raise RuntimeError("Mock autoscaling did not start")
                    time.sleep(0.01)
                callback = json.loads(signal.read_text())
                contender = run("contender")
                output, errors = actor.communicate(timeout=20)
                if actor.returncode:
                    raise RuntimeError(output + errors)
                outcome = json.loads(output)
                assert callback["pid"] != contender["pid"], "Connections must have different server PIDs"
                assert callback["callback_lock_held"] is baseline
                assert outcome["lock_after_return"] is False
                if baseline:
                    assert "MutexException" in contender["contender_result"]
                    assert 5 <= contender["wait_seconds"] < 6.5
                    assert outcome["operation_seconds"] >= 6
                else:
                    assert contender["contender_result"] == "reserved"
                    assert contender["wait_seconds"] < 2
                    assert outcome["operation_seconds"] < 2
                    assert outcome["calls_before_boundary"] == 0
                    assert outcome["calls"] == [{"method": "GET"}, {"method": "PATCH", "quantity": 1}]
                case = dict(baseline=baseline, operation=operation,
                            callback=callback, contender=contender, actor=outcome)
                report["cases"].append(case)
                print(json.dumps(case), flush=True)
                actor = None

        env["QUEUE_TEST_DELAY_US"] = "0"
        for boundary in ("push", "worker-loop", "after-release"):
            run("seed")
            outcome = run(boundary)
            assert outcome["lock_after_return"] is False
            expected = [{"method": "GET"}, {"method": "PATCH", "quantity": 1}]
            if boundary == "push":
                assert outcome["calls_before_boundary"] == 2, "Normal delayed pushes still scale immediately"
            else:
                assert outcome["calls_before_boundary"] == 0
            if boundary == "after-release":
                expected.append({"method": "PATCH", "quantity": 0})
            assert outcome["calls"] == expected
            report["cases"].append(dict(boundary=boundary, actor=outcome))
            print(json.dumps(report["cases"][-1]), flush=True)
        report["passed"] = True
    finally:
        if actor is not None and actor.poll() is None:
            actor.terminate()
            actor.communicate(timeout=10)
        if (tmp / "data/postmaster.pid").exists():
            subprocess.run([
                str(pg / "pg_ctl"), "-D", str(tmp / "data"), "-m", "immediate", "-w", "stop",
            ], check=True, stdout=subprocess.DEVNULL)
        assert not (tmp / "data/postmaster.pid").exists()
        shutil.rmtree(tmp)
        report["cleanup"] = {"cluster_stopped": True, "directory_removed": not tmp.exists()}
        if args.report:
            args.report.write_text(json.dumps(report, indent=2) + "\n")
        print("Owned PostgreSQL cluster stopped and removed.", flush=True)


if __name__ == "__main__":
    main()
