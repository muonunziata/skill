import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import instalar as ins  # noqa: E402


def test_pip_progress_moves_with_collected_packages_and_jumps_at_the_end():
    p = ins.PipProgress(expected=10)
    assert p.value == 0
    p.feed("Collecting a\nCollecting b\n  Downloading b.whl\n")
    assert p.value == 0.2
    assert p.feed("Collecting c\r\nCollecting d\r") == 0.4          # progress-bar style \r separators are handled
    for _ in range(30):
        p.feed("Collecting x\n")
    assert p.value == 0.9                                           # never claims "done" while still collecting
    assert p.feed("Installing collected packages: a, b\n") == 0.93
    assert p.feed("Successfully installed a-1 b-2\n") == 1.0


def test_pip_progress_handles_text_split_across_reads():
    p = ins.PipProgress(expected=4)
    assert p.feed("Collecting one\nColl") == 0.25
    assert p.feed("ecting two\n") >= 0.25                           # a split keyword may be missed but never goes backwards or crashes


def test_browser_progress_counts_each_download():
    b = ins.BrowserProgress(downloads=3)
    assert b.feed("Downloading Chromium 141.0 (playwright build v1194) from https://x\n") == 0
    b.feed("|■■■       |  30% of 160.8 MiB")
    assert 0.09 < b.value < 0.11
    b.feed("|■■■■■■■■■■| 100% of 160.8 MiB\nChromium downloaded to /x\n")
    b.feed("Downloading FFMPEG playwright build v1011\n")
    assert b.done == 1 and b.value < 0.7
    b.feed("|■■■■■     |  50% of 1.1 MiB")
    assert 0.45 < b.value < 0.55
    b.feed("|■■■■■■■■■■| 100% of 1.1 MiB\n")
    b.feed("Downloading Chromium Headless Shell\n|■■        |  20% of 100 MiB")
    assert b.done == 2 and b.value > 0.66


def test_bar_time_and_stall_messages():
    assert ins.render_bar(0.5, 10, unicode_ok=False) == "[#####-----]  50%"
    assert ins.render_bar(2, 4, unicode_ok=False).endswith("100%") and ins.render_bar(-1, 4, unicode_ok=False).endswith("  0%")
    assert ins.fmt_time(75) == "01:15"
    assert ins.stall_note(5) == "" and "lento" in ins.stall_note(60) and "SIN MOVIMIENTO" in ins.stall_note(200)


def test_a_quiet_step_still_shows_it_is_alive(tmp_path, monkeypatch, capsys):
    """A command that prints nothing for a while must keep updating the meter (spinner + timer + idle notice)."""
    import io

    monkeypatch.setattr(ins, "LOG", tmp_path / "log.txt")
    monkeypatch.setattr(ins, "ROOT", tmp_path)
    monkeypatch.setattr(ins, "SLOW_AFTER", 1)

    class Out(io.StringIO):
        def isatty(self):
            return True

    out = Out()
    meter = ins.Meter(out)
    code = ins.run_step(meter, "Paso lento", 0.5, [sys.executable, "-c", "import time; time.sleep(2.2); print('fin')"])
    text = out.getvalue()
    assert code == 0 and text.count("\r") >= 5                      # redrawn many times although nothing was printed
    assert "lento" in text and "Paso lento" in text and "00:0" in text
