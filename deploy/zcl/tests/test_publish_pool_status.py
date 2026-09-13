import copy
import importlib.util
from pathlib import Path
import unittest


SPEC = importlib.util.spec_from_file_location('pool_status', Path(__file__).parents[1] / 'publish-pool-status.py')
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
NOW = '2033-05-18T03:33:20Z'


def snapshot():
    result = MODULE.unavailable_mined(NOW)
    result.update(status='ok', unknownBlocks=0, excludedOrphans=1, accountingHeld=False)
    window = dict(rewardZat='7812500', blocks=2, matureRewardZat='3906250', matureBlocks=1,
                  immatureRewardZat='3906250', immatureBlocks=1)
    for name in ('allTime', 'last24h', 'lastHour'):
        result[name] = copy.deepcopy(window)
    return result


class MinedPublisherTests(unittest.TestCase):
    def test_accepts_complete_summary(self):
        data = snapshot()
        self.assertEqual(MODULE.sanitized_mined(data, NOW), data)

    def test_extra_fields_never_published(self):
        data = snapshot()
        data['privateFixture'] = 'never publish arbitrary fields'
        data['allTime']['accountFixture'] = 'private'
        self.assertEqual(MODULE.sanitized_mined(data, NOW), snapshot())

    def test_unavailable_never_fabricates_zero(self):
        data = snapshot()
        data['status'] = 'unavailable'
        self.assertIsNone(MODULE.sanitized_mined(data, NOW)['allTime'])

    def test_partial_keeps_known_totals(self):
        data = snapshot()
        data.update(status='partial', unknownBlocks=1)
        self.assertEqual(MODULE.sanitized_mined(data, NOW)['lastHour']['rewardZat'], '7812500')

    def test_malformed_or_inconsistent_summary_rejected(self):
        patches = [
            ('status', 'ok', lambda d: d.update(unknownBlocks=1)),
            ('status', 'ok', lambda d: d.update(accountingHeld=True)),
            ('generatedAt', '2000-01-01T00:00:00Z', None),
            ('generatedAt', '2033-05-18T03:33:20', None),
            ('asset', 'ZEC', None), ('unknownBlocks', True, None),
            ('lastHour', None, None), ('windowBasis', 'estimated', None),
        ]
        for field, value, mutate in patches:
            with self.subTest(field=field, value=value):
                data = snapshot()
                data[field] = value
                if mutate:
                    mutate(data)
                with self.assertRaises((ValueError, TypeError)):
                    MODULE.sanitized_mined(data, NOW)

    def test_amounts_counts_conservation_and_nested_windows(self):
        for field, value in [('rewardZat', 7812500), ('rewardZat', '-1'), ('rewardZat', '1e8'),
                             ('rewardZat', '7812501'), ('blocks', 3), ('blocks', True)]:
            with self.subTest(field=field, value=value):
                data = snapshot()
                data['allTime'][field] = value
                with self.assertRaises(ValueError):
                    MODULE.sanitized_mined(data, NOW)
        data = snapshot()
        data['last24h'].update(rewardZat='3906250', blocks=1, immatureRewardZat='0', immatureBlocks=0)
        with self.assertRaises(ValueError):
            MODULE.sanitized_mined(data, NOW)

    def test_reward_strings_have_at_most_24_digits(self):
        for digits in (24, 25):
            data = snapshot()
            for name in ('allTime', 'last24h', 'lastHour'):
                data[name].update(rewardZat='9' * digits, matureRewardZat='9' * digits,
                                  immatureRewardZat='0', blocks=1, matureBlocks=1, immatureBlocks=0)
            if digits == 24:
                self.assertEqual(MODULE.sanitized_mined(data, NOW), data)
            else:
                with self.assertRaises(ValueError):
                    MODULE.sanitized_mined(data, NOW)

    def test_zero_blocks_cannot_have_positive_rewards(self):
        for counts in ((0, 0, 0), (1, 0, 1), (1, 1, 0)):
            with self.subTest(counts=counts):
                data = snapshot()
                for name in ('allTime', 'last24h', 'lastHour'):
                    data[name].update(blocks=counts[0], matureBlocks=counts[1], immatureBlocks=counts[2])
                with self.assertRaises(ValueError):
                    MODULE.sanitized_mined(data, NOW)


if __name__ == '__main__':
    unittest.main()
