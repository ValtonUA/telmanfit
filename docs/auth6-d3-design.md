# AUTH-6B / D3-Design — два друзі як recovery trustees

**Статус:** документаційний кандидат для review. **D3 operational readiness не затверджено.**  
**База:** `master` `2dc52566c47e5dbbc894b96d7c9e801b5757ba18` (після PR #21/B6).  
**Обсяг:** жодних змін коду, БД, SQL, DBML, міграцій, production, ключів чи секретів.

## 1. Рішення власника, які вже зафіксовано

- **Trustee A** і **Trustee B** — **двоє різних друзів власника**. Попередня ідея «дівчина + друг» замінена.
- Для незалежної перевірки особи підходить **особиста зустріч**. Особисте повідомлення другові — спосіб **ініціювати** запит, не достатній proof: акаунт відправника можуть захопити.
- Не вимагати ПІБ, паспортних даних, адрес і телефонів **у TelmanFit/GitHub**. Контактні канали та інструкції можуть бути потрібні лише в **окремому захищеному offline/external runbook**, але рішення про їхнє зберігання ще відкрите.
- Друзі не отримують Filament/admin доступ і не мають права безпосередньо скидати пароль чи MFA.
- Це **призначення ролей на рівні вимог**, а не реєстрація реальних `auth_recovery_principals` або `auth_recovery_keys`. Згода друзів, їхні ключі й зовнішній registry ще не підготовлені.

Чинні D1/D2 без змін: **два незалежні approvals**, target/initiator/operator не можуть схвалювати власний recovery; case ≤72 год, approval ≤48 год, 24-годинний hold після другого approval, одноразовий grant ≤15 хв, pending TOTP ≤10 хв. Для заміни primary потрібен **явний signed scope**. Не змінювати ці TTL і ролі під виглядом спрощення.

## 2. Простий сценарій для власника та друзів

1. Власник утратив пароль, TOTP, резервні коди або доступ після аварії; звертається до двох друзів і захищеного intake. Створюється **один конкретний** recovery case з action, target, registry version, epoch, nonce, expiry і незмінним intent digest.
2. **Кожен друг окремо** перевіряє особу власника через попередньо визначений незалежний канал і **друге незалежне джерело**. Найпростіший бажаний варіант — особиста зустріч. Самого Telegram/пошти/нікнейма недостатньо. Перевіряється не лише «це він», а й **яку саме дію** просять виконати.
3. Кожен отримує **точний однаковий intent** із автентифікованого джерела, підписує його **своїм** Ed25519 ключем та додає власний evidence digest. Підпис A не передається як делегована згода B; два акаунти/ключі однієї людини не дають двох approvals.
4. **Verifier** перевіряє обидва підписи, приналежність keys до A/B, чинний **зовнішній** signed registry/revocation, expiry, target/action, initiator/operator exclusions, nonce, policy, epoch і evidence receipts. Запис «так» у чаті не є attestation.
5. Після другого valid approval починається **24 години hold**, відправляються незалежні повідомлення; dispute, revoke або зникнення verifier/registry → fail-closed. Пізні/прострочені approvals не продовжуються — новий case.
6. Лише після hold вузький restricted **operator** викликає існуючий `RecoveryService` (не прямий SQL). Одноразовий grant **не створює повну сесію**. Новий primary за потреби + **новий TOTP** і recovery batch; старі сесії/доступи анулюються. Власник проходить **новий повний login**.
7. Для останнього admin не існує password-only або emergency bypass. B5 restore fence/issuance≥24h/recovery_required/epoch залишаються незалежними заборонами. Відновлена БД сама не є доказом актуального trust registry.

Друзі підтверджують особу й exact intent, **не виконують технічні операції** з базою даних.

## 3. Дані, ключі та незалежний первинний bootstrap

| Місце | Що зберігати | Що НЕ зберігати |
|---|---|---|
| B3 PostgreSQL (після окремого дозволу) | Два principal UUID, `trustee` authority, immutable identity digest, публічні Ed25519 ключі, fingerprints, registry revision, validity/revoke, typed approvals/events | Приватні ключі, паспорти, обов'язкові ПІБ/телефони, сирі записи розмов |
| Зовнішній захищений registry | Актуальний signed manifest із version/hash chain, відповідність A/B ↔ fingerprint, історія revoke/rotation, зашифровані receipts bootstrap | Можливість тихого редагування app runtime/DB owner; чужі приватні ключі |
| Кожен друг окремо | **Свій приватний** Ed25519 key у безпечному сховищі/на особистому пристрої; перевірений fingerprint; узгоджена інструкція підпису | Один спільний ключ, key іншого друга, production/DB credentials |
| Окремий contact runbook (лише за потреби) | Відомі наперед незалежні callback channels та правила підтвердження, доступні тільки уповноваженим | Контакти у GitHub/PR/публічному журналі |

**Ключова проблема:** криптографія перевіряє, що підпис належить певному ключу, **але не те, кому він належить у реальному житті**. Під час **першої реєстрації** чинний [B2 §3.1](auth6-b2-design.md) вимагає, щоб **власник + незалежний технічний свідок (witness)** перевірили двох друзів, зв'язок осіб із public fingerprints і підписали registry manifest. Не можна скасувати цю вимогу документом. Owner як target не є recovery approver.

Потреба в witness є **одноразовою при bootstrap/контрольованій заміні trust**, а не автоматично вимогою постійного третього учасника при кожному recovery. Його реальна особа, повноваження та процедура — **відкриті D3-рішення**.

**Не генерувати ключі «за друзів» у Codex/CI і не публікувати приватні ключі або особисті дані.** Наявні B3 Ed25519/DB таблиці й історію не змінюємо.

## 4. Варіанти verifier та operator

Verifier — обмежений механізм перевірки **підписів і незалежного реєстру**, operator — обмежений технічний виконавець. Жоден із них не рахується approval і не може сам підписати замість друга.

| Варіант | Зручність | Що необхідно / ризик | Статус |
|---|---|---|---|
| **A. Незалежний автоматичний verifier + restricted operator** | Друзі лише незалежно перевіряють запит і підписують; **не потрібна постійна третя людина** | Потрібні незалежні runtime/custody, актуальний зовнішній registry/revocation, автентифікований intent/sign UX та безпечний одноразовий grant. Verifier на **тому самому VPS під тим самим root** не доводить незалежність від компрометації сервера | **Рекомендований кандидат, ще не погоджено** |
| **B. Окремий уповноважений технічний черговий/verifier для кожного recovery** | Друзі підписують, технічну частину веде людина | Залежність від доступності третьої людини, окремої custody, audit і чіткої заборони самостійного approval | Альтернатива |
| **C. Дві відповіді «так» у месенджері або ручний SQL reset** | Найменше кроків | Вразливість до викрадення акаунтів, немає signed intent/verifier, обхід B3 і двох незалежних approvals | **Відхилити — не відповідає D1/D2** |

Рекомендація — **спроєктувати A**, але не активувати до окремого рішення про trust boundary та доказів D8. Якщо незалежної інфраструктури немає, recovery **залишається закритим**, а не стає двома Telegram-повідомленнями.

Автоматизувати перевірку двох **криптографічних** підписів можливо, але це **не** автоматизує людське встановлення особи: друзі мають незалежно засвідчити її. Ніякий «автоматичний verifier» не може гарантувати, що зустріч відбулася, лише перевіривши підпис без відповідного provenance/receipt. Це лишається ризиком процесу.

## 5. Безпечна ротація, інциденти та відмови

- **Початкова реєстрація:** явна згода A/B → owner + independent witness перевіряють зв'язок із ключами → signed external registry revision → контрольований імпорт тільки публічних ключів/identity digest після окремого дозволу; у цьому PR **нічого не реєструється**.
- **Один друг втратив ключ, owner має доступ:** відкликати старий key через переглянуту registry revision; новий ключ, незалежний witness/dual-control за погодженою policy; наявні approvals зі старої revision недійсні. Ніякого перезапису історії.
- **Один друг недоступний, owner утратив доступ:** **одного approval недостатньо**, case expires/закривається. Тимчасового bypass або нового trustee «зі слів власника» немає; незалежна організаційна процедура відновлення довіри потребує окремого D3-рішення.
- **Підозра на компрометацію ключа/verifier/registry:** fail-closed, revoke, audit, незалежне оповіщення; старі approvals/grants не можна використати. Змова обох trustees і скомпрометований external root — окремі залишкові ризики.
- **Disaster restore:** актуальна зовнішня registry revision/epoch/holds перевіряються перед будь-якою дією; старі sessions/OTP/TOTP/grants не повертають доступу; B5 24h fence не скорочується.
- **Outage:** немає verifier, external manifest або trusted grant channel → немає автоматичного відновлення; не робити ручний SQL/direct login.

## 6. Що ще має погодити власник перед імплементацією D3

| Рішення | Обов'язковий результат |
|---|---|
| D3.1 — Друзі/згода та формат bootstrap | Двоє незалежних фізичних осіб, згода, owner + **незалежний witness**; без ПІБ/телефонів у Git/БД |
| D3.2 — Як друзі отримують/зберігають Ed25519 keys | Проста інструкція без програмування, окреме custody й revocation, тільки public fingerprints у сервісі |
| D3.3 — Які два незалежні докази/callback channels кожен використовує | Особиста зустріч або перевірена альтернативна policy; messenger-only недостатньо; мінімальні evidence receipts |
| D3.4 — Хто тримає підписаний **зовнішній** registry, версії, revocations і backup | Незалежний від runtime/DB owner актуальний manifest; fail-closed на missing/stale |
| D3.5 — Технічна verifier authority, receipts, operator/intake/incident lead | Окремі ролі; operator не approver; нуль прямого DB reset; authenticated grant delivery |
| D3.6 — Ротація/втрата trustees/ключів, dispute, recovery owner/last-admin | Один approval ніколи не достатній; сценарії доступності й компрометації перевірені |
| D3.7 — D8 custody/hosting/epoch/notices/backup, B7/P5/P6 review | Реальні operational drills перед production; окремий go/no-go |

**Майбутні тести:** synthetic last-admin recovery, lost-all credentials, two principals/Ed25519 vectors, tampered intent, duplicate keys/alias, forged verifier receipts, missing/revoked/stale registry, case/approval/hold/grant expiries, replay, broken callback provenance, notice failure, release gate/new TOTP/new login, concurrent revoke/finalize, restored stale epoch, external outage та lost-signing-device. Потрібні незалежні PG/HTTP/browser процеси, мінімізація доказів і негативні ACL-тести.

## 7. Наступний етап після review — не частина цього PR

Окремий implementation PR може створити адаптери до чинних `RecoveryBoundary::authorize/receipt/context`, механізм реєстрації лише public keys і інтеграцію із зовнішнім signed registry, спершу **тільки на synthetic keys та ізольованому PostgreSQL**. Жодних реальних trustees/ключів, production recovery, SMTP/hosting, bypass `4040404` або зняття B5 fences до затвердження D3/D8 та окремої перевірки. D3-Design не закриває P5/P6/B7.

**Чинні джерела:** [B2 §3, §8–9](auth6-b2-design.md), [B3](auth6-b3.md), [B4](auth6-b4.md), [B5](auth6-b5.md), [B6](auth6-b6.md), [P5/P6 readiness](auth6-readiness.md). Усі рішення, не позначені явно як вибір власника, є **пропозиціями**.
