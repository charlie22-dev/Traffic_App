import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from evaluate import metrics


class MetricsTest(unittest.TestCase):
    def test_hand_calculated_absolute_metrics_and_inclusive_sixty_second_boundary(self):
        result = metrics([-90, -60, 0, 30])
        self.assertEqual(result['n'], 4)
        self.assertEqual(result['mae_seconds'], 45)
        self.assertEqual(result['median_absolute_error_seconds'], 45)
        self.assertEqual(result['within_60_percent'], 75)

    def test_no_arrivals_is_not_reported_as_perfect_accuracy(self):
        result = metrics([])
        self.assertEqual(result['n'], 0)
        self.assertIsNone(result['mae_seconds'])
        self.assertIsNone(result['within_60_percent'])
