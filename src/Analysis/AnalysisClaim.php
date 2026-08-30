<?php

declare(strict_types=1);

namespace JournalingPostServer\Analysis;

/**
 * Idempotency-Keyに対するAI呼び出し権の取得結果。
 */
enum AnalysisClaim
{
    /** このrequestがAI解析を実行してよい。 */
    case Granted;

    /** 同じkeyの解析が処理中である。時間を置いて再送すればよい。 */
    case InProgress;

    /** 同じkeyの解析が完了済みである。結果は引き渡しバッファから返す。 */
    case Completed;

    /** 同じkeyが別内容のrequestで使われた。retryではなく client の誤りである。 */
    case KeyReuse;
}
