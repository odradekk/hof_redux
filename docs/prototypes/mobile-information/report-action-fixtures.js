// Throwaway display fixtures for the mobile battle-report prototype.
// This is deliberately not a production combat event format.
const reportActionFixtures = [
  {
    actorId: 'a', actionLabel: '蓄力', kind: 'charge', targetIds: [],
    events: [
      { kind: 'charge', targetId: 'a' },
      // 复核：尚未扣除 SP，也没有锁定目标。
      { kind: 'info' },
    ],
  },
  {
    actorId: 'a', actionLabel: '攻击', kind: 'attack', targetIds: ['g1'],
    events: [{ kind: 'sp', targetId: 'a', amount: 4 }, { kind: 'damage', targetId: 'g1', amount: 18 }],
  },
  {
    actorId: 'b1', actionLabel: '攻击', kind: 'attack', targetIds: ['a'],
    events: [{ kind: 'damage', targetId: 'a', amount: 20 }],
  },
  {
    actorId: 'h', actionLabel: '治疗', kind: 'heal', targetIds: ['a'],
    events: [{ kind: 'sp', targetId: 'h', amount: 4 }, { kind: 'heal', targetId: 'a', amount: 12 }],
  },
  {
    actorId: 'm', actionLabel: '攻击', kind: 'attack', targetIds: ['g1'],
    events: [{ kind: 'sp', targetId: 'm', amount: 5 }, { kind: 'damage', targetId: 'g1', amount: 12 }],
  },
  {
    actorId: 'r', actionLabel: '攻击', kind: 'attack', targetIds: ['g1'],
    events: [
      { kind: 'damage', targetId: 'g1', amount: 10 },
      { kind: 'death', targetId: 'g1' },
      // 复核：仅是展示用的击杀收益记录。
      { kind: 'reward', amount: 120 },
    ],
  },
  {
    actorId: 'boss', actionLabel: '攻击', kind: 'attack', targetIds: ['s'],
    events: [{ kind: 'sp', targetId: 'boss', amount: 8 }, { kind: 'damage', targetId: 's', amount: 30 }],
  },
  {
    actorId: 's', actionLabel: '攻击', kind: 'attack', targetIds: ['boss'],
    events: [{ kind: 'damage', targetId: 'boss', amount: 35 }],
  },
  {
    actorId: 'b2', actionLabel: '施加中毒', kind: 'status', targetIds: ['a'],
    events: [{ kind: 'sp', targetId: 'b2', amount: 5 }, { kind: 'status', targetId: 'a', statusName: '中毒' }],
  },
  {
    actorId: 'boss', actionLabel: '召唤', kind: 'summon', targetIds: ['summon1'],
    events: [
      { kind: 'sp', targetId: 'boss', amount: 10 },
      { kind: 'summon', targetId: 'summon1' },
    ],
  },
  {
    actorId: 'r', actionLabel: '攻击', kind: 'attack', targetIds: ['summon1'],
    events: [{ kind: 'damage', targetId: 'summon1', amount: 20 }],
  },
  {
    actorId: 'm', actionLabel: '攻击', kind: 'attack', targetIds: ['g2', 'g3'],
    events: [
      { kind: 'sp', targetId: 'm', amount: 8 },
      { kind: 'damage', targetId: 'g2', amount: 25 },
      { kind: 'damage', targetId: 'g3', amount: 25 },
    ],
  },
  {
    actorId: 'a', actionLabel: '攻击', kind: 'attack', targetIds: ['boss'],
    events: [
      { kind: 'damage', targetId: 'boss', amount: 30 },
      // 复核：行动后的中毒自伤不是本次主动目标。
      { kind: 'damage', targetId: 'a', amount: 4 },
    ],
  },
  {
    actorId: 'h', actionLabel: '治疗', kind: 'heal', targetIds: ['s'],
    events: [{ kind: 'sp', targetId: 'h', amount: 6 }, { kind: 'heal', targetId: 's', amount: 25 }],
  },
  {
    actorId: 'boss', actionLabel: '攻击', kind: 'attack', targetIds: ['s'],
    events: [
      { kind: 'sp', targetId: 'boss', amount: 15 },
      { kind: 'damage', targetId: 's', amount: 95 },
      { kind: 'death', targetId: 's' },
    ],
  },
  {
    actorId: 'r', actionLabel: '攻击', kind: 'attack', targetIds: ['b1'],
    events: [{ kind: 'damage', targetId: 'b1', amount: 30 }],
  },
  {
    actorId: 'm', actionLabel: '待机', kind: 'wait', targetIds: [],
    events: [
      { kind: 'wait' },
      // 复核：待机没有隐式改为普通攻击。
      { kind: 'info' },
    ],
  },
  {
    actorId: 'h', actionLabel: '咏唱', kind: 'charge', targetIds: [],
    events: [
      { kind: 'charge', targetId: 'h' },
      // 复核：开始等待时没有 SP 成本。
      { kind: 'info' },
    ],
  },
  {
    actorId: 'g3', actionLabel: '攻击', kind: 'attack', targetIds: ['a'],
    events: [{ kind: 'damage', targetId: 'a', amount: 15 }],
  },
  {
    actorId: 'h', actionLabel: '治疗', kind: 'heal', targetIds: ['a'],
    events: [{ kind: 'sp', targetId: 'h', amount: 6 }, { kind: 'heal', targetId: 'a', amount: 20 }],
  },
  {
    actorId: 'b1', actionLabel: '攻击', kind: 'attack', targetIds: ['r'],
    events: [{ kind: 'damage', targetId: 'r', amount: 10 }],
  },
  {
    actorId: 'a', actionLabel: '攻击', kind: 'attack', targetIds: ['boss'],
    events: [
      { kind: 'damage', targetId: 'boss', amount: 25 },
      // 复核：中毒自伤保留为同一步事件，不是主动目标。
      { kind: 'damage', targetId: 'a', amount: 4 },
    ],
  },
  {
    actorId: 'r', actionLabel: '攻击', kind: 'attack', targetIds: ['summon1'],
    events: [
      // 伤害、死亡及退出阵型说明分别表达。
      { kind: 'damage', targetId: 'summon1', amount: 50 },
      { kind: 'death', targetId: 'summon1' },
      { kind: 'info' },
    ],
  },
  {
    actorId: 'm', actionLabel: '防御', kind: 'defend', targetIds: [],
    events: [
      { kind: 'defend' },
      // 复核：这是预置样例的结束说明。
      { kind: 'info' },
    ],
  },
];
