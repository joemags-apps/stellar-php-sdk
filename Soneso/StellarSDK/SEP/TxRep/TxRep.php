<?php declare(strict_types=1);

// Copyright 2022 The Stellar PHP SDK Authors. All rights reserved.
// Use of this source code is governed by a license that can be
// found in the LICENSE file.

namespace Soneso\StellarSDK\SEP\TxRep;

use DateTime;
use Exception;
use InvalidArgumentException;
use phpseclib3\Math\BigInteger;
use Soneso\StellarSDK\AbstractOperation;
use Soneso\StellarSDK\Account;
use Soneso\StellarSDK\AccountMergeOperation;
use Soneso\StellarSDK\AccountMergeOperationBuilder;
use Soneso\StellarSDK\AllowTrustOperation;
use Soneso\StellarSDK\AllowTrustOperationBuilder;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum;
use Soneso\StellarSDK\AssetTypeNative;
use Soneso\StellarSDK\BumpSequenceOperation;
use Soneso\StellarSDK\BumpSequenceOperationBuilder;
use Soneso\StellarSDK\ChangeTrustOperation;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\CreateAccountOperation;
use Soneso\StellarSDK\CreateAccountOperationBuilder;
use Soneso\StellarSDK\CreatePassiveSellOfferOperation;
use Soneso\StellarSDK\CreatePassiveSellOfferOperationBuilder;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Crypto\StrKey;
use Soneso\StellarSDK\FeeBumpTransaction;
use Soneso\StellarSDK\FeeBumpTransactionBuilder;
use Soneso\StellarSDK\ManageBuyOfferOperation;
use Soneso\StellarSDK\ManageBuyOfferOperationBuilder;
use Soneso\StellarSDK\ManageDataOperation;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\ManageSellOfferOperation;
use Soneso\StellarSDK\ManageSellOfferOperationBuilder;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\PathPaymentStrictReceiveOperation;
use Soneso\StellarSDK\PathPaymentStrictReceiveOperationBuilder;
use Soneso\StellarSDK\PathPaymentStrictSendOperation;
use Soneso\StellarSDK\PathPaymentStrictSendOperationBuilder;
use Soneso\StellarSDK\PaymentOperation;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Price;
use Soneso\StellarSDK\SetOptionsOperation;
use Soneso\StellarSDK\SetOptionsOperationBuilder;
use Soneso\StellarSDK\TimeBounds;
use Soneso\StellarSDK\Transaction;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\Util\StellarAmount;
use Soneso\StellarSDK\Xdr\XdrBuffer;
use Soneso\StellarSDK\Xdr\XdrDecoratedSignature;
use Soneso\StellarSDK\Xdr\XdrEnvelopeType;
use Soneso\StellarSDK\Xdr\XdrOperationType;
use Soneso\StellarSDK\Xdr\XdrSignerKey;
use Soneso\StellarSDK\Xdr\XdrSignerKeyType;
use Soneso\StellarSDK\Xdr\XdrTransactionEnvelope;

class TxRep
{
    public static function fromTransactionEnvelopeXdrBase64(string $transactionEnvelopeXdrBase64) : string {

        $tx = null;
        $feeBump = null;
        $feeBumpSignatures = null;

        $xdr = base64_decode($transactionEnvelopeXdrBase64);
        $xdrBuffer = new XdrBuffer($xdr);
        $envelopeXdr = XdrTransactionEnvelope::decode($xdrBuffer);

        switch ($envelopeXdr->getType()->getValue()) {
            case XdrEnvelopeType::ENVELOPE_TYPE_TX_V0:
                $tx = Transaction::fromV0EnvelopeXdr($envelopeXdr->getV0());
                break;
            case XdrEnvelopeType::ENVELOPE_TYPE_TX:
                $tx = Transaction::fromV1EnvelopeXdr($envelopeXdr->getV1());
                break;
            case XdrEnvelopeType::ENVELOPE_TYPE_TX_FEE_BUMP:
                $feeBump = FeeBumpTransaction::fromFeeBumpTransactionEnvelope($envelopeXdr->getFeeBump());
                $tx = $feeBump->getInnerTx();
                $feeBumpSignatures = $envelopeXdr->getFeeBump()->getSignatures();
                break;
        }
        $isFeeBump = $feeBump != null;
        $lines = array();
        $type = $isFeeBump ? 'ENVELOPE_TYPE_TX_FEE_BUMP' : 'ENVELOPE_TYPE_TX';
        $prefix = $isFeeBump ? 'feeBump.tx.innerTx.tx.' : 'tx.';
        $lines += ['type' => $type];

        if ($isFeeBump) {
            $lines += ['feeBump.tx.feeSource' => $feeBump->getFeeAccount()->getAccountId()];
            $lines += ['feeBump.tx.fee' => strval($feeBump->getFee())];
            $lines += ['feeBump.tx.innerTx.type' => 'ENVELOPE_TYPE_TX'];
        }

        $lines += [$prefix.'sourceAccount' => $tx->getSourceAccount()->getAccountId()];
        $lines += [$prefix.'fee' => strval($tx->getFee())];
        $lines += [$prefix.'seqNum' => $tx->getSequenceNumber()->toString()];

        $timeBounds = $tx->getTimeBounds();
        if ($timeBounds) {
            $lines += [$prefix.'timeBounds._present' => 'true'];
            $lines += [$prefix.'timeBounds.minTime' => strval($timeBounds->getMinTime()->getTimestamp())];
            $lines += [$prefix.'timeBounds.maxTime' => strval($timeBounds->getMaxTime()->getTimestamp())];
        } else {
            $lines += [$prefix.'timeBounds._present' => 'false'];
        }

        $memo = $tx->getMemo();
        if ($memo->getType() == Memo::MEMO_TYPE_NONE) {
            $lines += [$prefix.'memo.type' => 'MEMO_NONE'];
        } else if ($memo->getType() == Memo::MEMO_TYPE_TEXT) {
            $lines += [$prefix.'memo.type' => 'MEMO_TEXT'];
            $lines += [$prefix.'memo.text' => json_encode($memo->getValue())];
        } else if ($memo->getType() == Memo::MEMO_TYPE_ID) {
            $lines += [$prefix.'memo.type' => 'MEMO_ID'];
            $lines += [$prefix.'memo.id' => strval($memo->getValue())];
        } else if ($memo->getType() == Memo::MEMO_TYPE_HASH) {
            $lines += [$prefix.'memo.type' => 'MEMO_HASH'];
            $lines += [$prefix.'memo.hash' => $memo->getValue()];
        } else if ($memo->getType() == Memo::MEMO_TYPE_RETURN) {
            $lines += [$prefix.'memo.type' => 'MEMO_RETURN'];
            $lines += [$prefix.'memo.retHash' => $memo->getValue()];
        }

        $operations = $tx->getOperations();
        $lines += [$prefix.'operations.len' => count($operations)];

        $index = 0;
        foreach ($operations as $operation) {
            $operationLines = self::getOperationTx($operation, $index, $prefix);
            $lines = array_merge($lines, $operationLines);
            $index++;
        }

        $lines += [$prefix.'ext.v' => '0'];
        $p = $isFeeBump ? 'feeBump.tx.innerTx.' : '';
        $lines = array_merge($lines, self::getSignatures($p, $tx->getSignatures()));
        if ($isFeeBump) {
            $lines += ['feeBump.tx.ext.v' => '0'];
            $lines = array_merge($lines, self::getSignatures('feeBump.', $feeBumpSignatures));
        }

        $result = "";
        $keys = array_keys($lines);
        $countKeys = count($keys);
        foreach ($keys as $key) {
            $result = $result . $key . ': ' . $lines[$key];
            if ($keys[$countKeys - 1] != $key) {
                $result = $result . PHP_EOL;
            }
        }
        return $result;
    }

    public static function transactionEnvelopeXdrBase64FromTxRep(string $txRep) : string {
        $lines = explode(PHP_EOL, $txRep);
        $map = array();
        foreach($lines as $line) {
            $line = trim($line);
            if ($line == "") {
                continue;
            }
            $parts = explode(':', $line);
            if (count($parts) > 1) {
               $key = $parts[0];
               $value = trim(implode(':', array_slice($parts, 1)));
               $map += [$key => $value];
            }
        }
        $prefix = 'tx.';
        $isFeeBump = self::getClearValue('type', $map) == 'ENVELOPE_TYPE_TX_FEE_BUMP';
        $feeBumpFee = null;
        $feeBumpSource =  self::getClearValue('feeBump.tx.feeSource', $map);

        if ($isFeeBump) {
            $prefix = 'feeBump.tx.innerTx.tx.';
            $feeBumpFeeStr = self::getClearValue('feeBump.tx.fee', $map);
            if (!$feeBumpFeeStr) {
                throw new InvalidArgumentException('missing feeBump.tx.fee');
            }
            if (!is_numeric($feeBumpFeeStr)) {
                throw new InvalidArgumentException('invalid feeBump.tx.fee');
            }

            $feeBumpFee = (int)$feeBumpFeeStr;

            if (!$feeBumpSource) {
                throw new InvalidArgumentException('missing feeBump.tx.feeSource');
            }

            $feeBumpSourceKeyPair = null;
            try {
                $feeBumpSourceKeyPair = KeyPair::fromAccountId($feeBumpSource);
            } catch (Exception $e) {
                throw new InvalidArgumentException('invalid feeBump.tx.feeSource');
            }
            if (!$feeBumpSourceKeyPair) {
                throw new InvalidArgumentException('invalid feeBump.tx.feeSource');
            }
        }

        $sourceAccountId = self::getClearValue($prefix.'sourceAccount', $map);
        if (!$sourceAccountId) {
            throw new InvalidArgumentException('missing '.$prefix.'sourceAccount');
        }
        $sourceAccountKeyPair = null;
        try {
            $sourceAccountKeyPair = KeyPair::fromAccountId($sourceAccountId);
        } catch (Exception $e) {
            throw new InvalidArgumentException('invalid '.$prefix.'sourceAccount');
        }
        if (!$sourceAccountKeyPair) {
            throw new InvalidArgumentException('invalid '.$prefix.'sourceAccount');
        }
        $feeStr = self::getClearValue($prefix.'fee', $map);
        if (!$feeStr || !is_numeric($feeStr)) {
            throw new InvalidArgumentException('missing or invalid '.$prefix.'fee');
        }
        $fee = (int)$feeStr;
        $sequenceNumberStr = self::getClearValue($prefix.'seqNum', $map);
        if (!$sequenceNumberStr) {
            throw new InvalidArgumentException('missing '.$prefix.'seqNum');
        }
        $sequenceNumber = new BigInteger($sequenceNumberStr);
        if ($sequenceNumber->toString() != $sequenceNumberStr) {
            throw new InvalidArgumentException('invalid '.$prefix.'seqNum');
        }

        $sourceAccount = Account::fromAccountId($sourceAccountId, $sequenceNumber->subtract(new BigInteger(1)));
        $txBuilder = new TransactionBuilder($sourceAccount);

        $minTimeStr = self::getClearValue($prefix.'timeBounds.minTime', $map);
        $maxTimeStr = self::getClearValue($prefix.'timeBounds.maxTime', $map);
        if (self::getClearValue($prefix.'timeBounds._present', $map) == 'true' && $minTimeStr && $maxTimeStr) {
            $minTime = (int)$minTimeStr;
            $maxTime = (int)$maxTimeStr;
            $timeBounds = new TimeBounds((new DateTime)->setTimestamp($minTime), (new DateTime)->setTimestamp($maxTime));
            $txBuilder->setTimeBounds($timeBounds);
        }

        $memoType = self::getClearValue($prefix.'memo.type', $map);
        if (!$memoType) {
            throw new InvalidArgumentException('missing '.$prefix.'memo.type');
        }
        if ($memoType == 'MEMO_TEXT' && self::getClearValue($prefix.'memo.text', $map)) {
            $text = str_replace('"','', self::getClearValue($prefix.'memo.text', $map));
            $txBuilder->addMemo(Memo::text($text));
        } else if ($memoType == 'MEMO_ID' && self::getClearValue($prefix.'memo.id', $map)) {
            $val = self::getClearValue($prefix.'memo.id', $map);
            if (!is_numeric($val)) {
                throw new InvalidArgumentException($prefix.'memo.id');
            }
            $id = (int)self::getClearValue($prefix.'memo.id', $map);
            $txBuilder->addMemo(Memo::id($id));
        } else if ($memoType == 'MEMO_HASH' && self::getClearValue($prefix.'memo.hash', $map)) {
            $hash = hex2bin(self::getClearValue($prefix.'memo.hash', $map));
            if (!$hash) {
                throw new InvalidArgumentException($prefix.'memo.hash');
            }
            $txBuilder->addMemo(Memo::hash($hash));
        } else if ($memoType == 'MEMO_RETURN' && self::getClearValue($prefix.'memo.return', $map)) {
            $hash = hex2bin(self::getClearValue($prefix.'memo.return', $map));
            if (!$hash) {
                throw new InvalidArgumentException($prefix.'memo.return');
            }
            $txBuilder->addMemo(Memo::return($hash));
        } else {
            $txBuilder->addMemo(Memo::none());
        }

        $operationsLen = self::getClearValue($prefix.'operations.len', $map);
        if (!$operationsLen) {
            throw new InvalidArgumentException('missing '.$prefix.'operations.len');
        }
        if (!is_numeric($operationsLen)) {
            throw new InvalidArgumentException('invalid '.$prefix.'operations.len');
        }
        $nrOfOperations = (int)$operationsLen;
        if ($nrOfOperations > 100) {
            throw new InvalidArgumentException('invalid '.$prefix.'operations.len - greater than 100');
        }

        for ($i = 0; $i < $nrOfOperations; $i++) {
            $operation = self::getOperation($i, $map, $prefix);
            if ($operation) {
               $txBuilder->addOperation($operation);
            }
        }
        $maxOperationFee = intval(round((float)$fee / (float)$nrOfOperations));
        $txBuilder->setMaxOperationFee($maxOperationFee);
        $transaction = $txBuilder->build();

        // Signatures
        $prefix = $isFeeBump ? 'feeBump.tx.innerTx.' : "";
        $signaturesLen = self::getClearValue($prefix.'signatures.len', $map);
        if ($signaturesLen) {
            if(!is_numeric($signaturesLen)) {
                throw new InvalidArgumentException('invalid '.$prefix.'signatures.len');
            }
            $nrOfSignatures = intval($signaturesLen);
            if($nrOfSignatures > 20) {
                throw new InvalidArgumentException('invalid '.$prefix.'signatures.len - greater than 20');
            }
            $signatures = array();
            for($i = 0; $i< $nrOfSignatures; $i++) {
                $signature = self::getSignature($i, $map, $prefix);
                if($signature) {
                    array_push($signatures, $signature);
                }
            }
            $transaction->setSignatures($signatures);
        }
        if ($isFeeBump) {
           $builder =  new FeeBumpTransactionBuilder($transaction);
           $baseFee = intval(round((float)$feeBumpFee / (float)($nrOfOperations +  1)));
           $builder->setBaseFee($baseFee);
           $builder->setMuxedFeeAccount(MuxedAccount::fromAccountId($feeBumpSource));
           $feeBumpTransaction = $builder->build();
           $fbSignaturesLen = self::getClearValue('feeBump.signatures.len', $map);
            if ($fbSignaturesLen) {
                if(!is_numeric($fbSignaturesLen)) {
                    throw new InvalidArgumentException('invalid '.$prefix.'feeBump.signatures.len');
                }
                $nrOfFbSignatures = intval($fbSignaturesLen);
                if($nrOfFbSignatures > 20) {
                    throw new InvalidArgumentException('invalid '.$prefix.'feeBump.signatures.len - greater than 20');
                }
                $fbSignatures = array();
                for($i = 0; $i< $nrOfFbSignatures; $i++) {
                    $signature = self::getSignature($i, $map, 'feeBump.');
                    if($signature) {
                        array_push($fbSignatures, $signature);
                    }
                }
                $feeBumpTransaction->setSignatures($fbSignatures);
            }
            return $feeBumpTransaction->toEnvelopeXdrBase64();
        }
        return $transaction->toEnvelopeXdrBase64();
    }

    private static function getSignature(int $index, array $map, string $txPrefix) : ?XdrDecoratedSignature {
        $hintStr = self::getClearValue($txPrefix.'signatures['.$index.'].hint', $map);
        if (!$hintStr) {
            throw new InvalidArgumentException('missing '.$txPrefix.'signatures['.$index.'].hint');
        }
        $signatureStr = self::getClearValue($txPrefix.'signatures['.$index.'].signature', $map);
        if (!$signatureStr) {
            throw new InvalidArgumentException('missing '.$txPrefix.'signatures['.$index.'].signature');
        }
        $hint = hex2bin($hintStr);
        $signature = hex2bin($signatureStr);
        if ($hint && $signature) {
            return new XdrDecoratedSignature($hint, $signature);
        }
        return null;
    }

    private static function getOperation(int $index, array $map, string $txPrefix) : ?AbstractOperation {
        $prefix = $txPrefix.'operations['.$index.'].body.';
        $sourceAccountId = null;
        if (self::getClearValue($txPrefix.'operations['.$index.'].sourceAccount._present', $map) == 'true') {
            $sourceAccountId = self::getClearValue($txPrefix.'operations['.$index.'].sourceAccount', $map);
            if (!$sourceAccountId) {
                throw new InvalidArgumentException('missing '.$txPrefix.'operations['.$index.'].sourceAccount');
            }
            try {
                KeyPair::fromAccountId($sourceAccountId);
            } catch (Exception $e) {
                throw new InvalidArgumentException('invalid '.$txPrefix.'operations['.$index.'].sourceAccount');
            }
        }

        $opType = self::getClearValue($prefix.'type', $map);
        if (!$opType) {
            throw new InvalidArgumentException($prefix.'type');
        }
        if ($opType == 'CREATE_ACCOUNT') {
            $opPrefix = $prefix.'createAccountOp.';
            return self::getCreateAccountOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'PAYMENT') {
            $opPrefix = $prefix.'paymentOp.';
            return self::getPaymentOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'PATH_PAYMENT_STRICT_RECEIVE') {
            $opPrefix = $prefix.'pathPaymentStrictReceiveOp.';
            return self::getPathPaymentStrictReceiveOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'PATH_PAYMENT_STRICT_SEND') {
            $opPrefix = $prefix.'pathPaymentStrictSendOp.';
            return self::getPathPaymentStrictSendOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'MANAGE_SELL_OFFER') {
            $opPrefix = $prefix.'manageSellOfferOp.';
            return self::getManageSellOfferOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'CREATE_PASSIVE_SELL_OFFER') {
            $opPrefix = $prefix.'createPassiveSellOfferOp.';
            return self::getCreatePassiveSellOfferOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'SET_OPTIONS') {
            $opPrefix = $prefix.'setOptionsOp.';
            return self::getSetOptionsOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'CHANGE_TRUST') {
            $opPrefix = $prefix.'changeTrustOp.';
            return self::getChangeTrustOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'ALLOW_TRUST') {
            $opPrefix = $prefix.'allowTrustOp.';
            return self::getAllowTrustOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'ACCOUNT_MERGE') {
            // account merge does not include 'accountMergeOp' prefix
            return self::getAccountMergeOperation($index, $map, $txPrefix, $sourceAccountId);
        }
        if ($opType == 'MANAGE_DATA') {
            $opPrefix = $prefix.'manageDataOp.';
            return self::getManageDataOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'BUMP_SEQUENCE') {
            $opPrefix = $prefix.'bumpSequenceOp.';
            return self::getBumpSequenceOperation($opPrefix, $map, $sourceAccountId);
        }
        if ($opType == 'MANAGE_BUY_OFFER') {
            $opPrefix = $prefix.'manageBuyOfferOp.';
            return self::getManageBuyOfferOperation($opPrefix, $map, $sourceAccountId);
        }

        return null;
    }

    private static function getBumpSequenceOperation($opPrefix, array $map, ?string $sourceAccountId) : BumpSequenceOperation
    {
        $bumpToStr = self::getClearValue($opPrefix . 'bumpTo', $map);
        if (!$bumpToStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'bumpTo');
        }

        $sequenceNumber = new BigInteger($bumpToStr);
        if ($sequenceNumber->toString() != $bumpToStr) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'bumpTo');
        }
        $builder = new BumpSequenceOperationBuilder($sequenceNumber);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getManageDataOperation($opPrefix, array $map, ?string $sourceAccountId) : ManageDataOperation
    {
        $dataNameStr = self::getClearValue($opPrefix . 'dataName', $map);
        if (!$dataNameStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'dataName');
        }
        $dataName = str_replace('"','', $dataNameStr);

        $present = self::getClearValue($opPrefix . 'dataValue._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'dataValue._present');
        }

        $dataValue = null;
        if ($present == 'true') {
            $dataValueStr = self::getClearValue($opPrefix . 'dataValue', $map);
            if (!$dataValueStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'dataValue');
            }
            $dataValue = hex2bin($dataValueStr);
            if (!$dataValue) {
                throw new InvalidArgumentException('invalid ' . $opPrefix . 'dataValue');
            }
        }

        $builder = new ManageDataOperationBuilder($dataName, $dataValue);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getAccountMergeOperation($index, array $map, string $txPrefix, ?string $sourceAccountId) : AccountMergeOperation
    {
        $destination = self::getClearValue($txPrefix.'operations['.strval($index).'].body.destination', $map);
        if (!$destination) {
            throw new InvalidArgumentException('missing ' . $txPrefix.'operations['.strval($index).'].body.destination');
        }
        try {
            KeyPair::fromAccountId($destination);
        } catch (Exception $e) {
            throw new InvalidArgumentException('invalid ' . $txPrefix.'operations['.strval($index).'].body.destination');
        }
        $builder = new AccountMergeOperationBuilder($destination);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getAllowTrustOperation($opPrefix, array $map, ?string $sourceAccountId) : AllowTrustOperation
    {
        $trustor = self::getClearValue($opPrefix.'trustor', $map);
        if (!$trustor) {
            throw new InvalidArgumentException('missing '.$opPrefix.'trustor');
        }
        $assetCode = self::getClearValue($opPrefix.'asset', $map);
        if (!$assetCode) {
            throw new InvalidArgumentException('missing '.$opPrefix.'asset');
        }
        $authStr = self::getClearValue($opPrefix.'authorize', $map);
        if (!$authStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'authorize');
        }
        if (!is_numeric($authStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'authorize');
        }
        $authorize = intval($authStr);
        if($authorize < 0 || $authorize > 2) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'authorize');
        }
        $auth = $authorize == 1;
        $authToMaintainLiabilities = $authorize == 2;
        $builder = new AllowTrustOperationBuilder($trustor, $assetCode, $auth, $authToMaintainLiabilities);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getChangeTrustOperation(string $opPrefix, array $map, ?string $sourceAccountId) : ChangeTrustOperation
    {
        $assetStr = self::getClearValue($opPrefix . 'line', $map);
        if (!$assetStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'line');
        }
        $asset = Asset::createFromCanonicalForm($assetStr);
        if (!$asset) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'line');
        }

        $limitStr = self::getClearValue($opPrefix.'limit', $map);
        if (!$limitStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'limit');
        }
        if (!is_numeric($limitStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'limit');
        }
        $limit = self::fromAmount($limitStr);
        $builder = new ChangeTrustOperationBuilder($asset, $limit);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getSetOptionsOperation(string $opPrefix, array $map, ?string $sourceAccountId) : SetOptionsOperation
    {
        $present = self::getClearValue($opPrefix . 'inflationDest._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'inflationDest._present');
        }
        $inflationDest = null;
        if ($present == 'true') {
            $inflationDest = self::getClearValue($opPrefix . 'inflationDest', $map);
            if (!$inflationDest) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'inflationDest');
            }
        }

        $present = self::getClearValue($opPrefix . 'clearFlags._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'clearFlags._present');
        }
        $clearFlags = null;
        if ($present == 'true') {
            $clearFlagsStr = self::getClearValue($opPrefix . 'clearFlags', $map);
            if (!$clearFlagsStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'clearFlags');
            }
            if (!is_numeric($clearFlagsStr)) {
                throw new InvalidArgumentException('invalid ' . $opPrefix . 'clearFlags');
            }
            $clearFlags = (int)$clearFlagsStr;
        }

        $present = self::getClearValue($opPrefix . 'setFlags._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'setFlags._present');
        }
        $setFlags = null;
        if ($present == 'true') {
            $setFlagsStr = self::getClearValue($opPrefix . 'setFlags', $map);
            if (!$setFlagsStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'setFlags');
            }
            if (!is_numeric($setFlagsStr)) {
                throw new InvalidArgumentException('invalid ' . $opPrefix . 'setFlags');
            }
            $setFlags = (int)$setFlagsStr;
        }

        $present = self::getClearValue($opPrefix . 'masterWeight._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'masterWeight._present');
        }
        $masterWeight = null;
        if ($present == 'true') {
            $masterWeightStr = self::getClearValue($opPrefix . 'masterWeight', $map);
            if (!$masterWeightStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'masterWeight');
            }
            if (!is_numeric($masterWeightStr)) {
                throw new InvalidArgumentException('invalid ' . $opPrefix . 'masterWeight');
            }
            $masterWeight = (int)$masterWeightStr;
        }

        $present = self::getClearValue($opPrefix . 'lowThreshold._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'lowThreshold._present');
        }
        $lowThreshold = null;
        if ($present == 'true') {
            $lowThresholdStr = self::getClearValue($opPrefix . 'lowThreshold', $map);
            if (!$lowThresholdStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'lowThreshold');
            }
            if (!is_numeric($lowThresholdStr)) {
                throw new InvalidArgumentException('invalid ' . $opPrefix . 'lowThreshold');
            }
            $lowThreshold = (int)$lowThresholdStr;
        }

        $present = self::getClearValue($opPrefix . 'medThreshold._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'medThreshold._present');
        }
        $medThreshold = null;
        if ($present == 'true') {
            $medThresholdStr = self::getClearValue($opPrefix . 'medThreshold', $map);
            if (!$medThresholdStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'medThreshold');
            }
            if (!is_numeric($medThresholdStr)) {
                throw new InvalidArgumentException('invalid ' . $opPrefix . 'medThreshold');
            }
            $medThreshold = (int)$medThresholdStr;
        }

        $present = self::getClearValue($opPrefix . 'highThreshold._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'highThreshold._present');
        }
        $highThreshold = null;
        if ($present == 'true') {
            $highThresholdStr = self::getClearValue($opPrefix . 'highThreshold', $map);
            if (!$highThresholdStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'highThreshold');
            }
            if (!is_numeric($highThresholdStr)) {
                throw new InvalidArgumentException('invalid ' . $opPrefix . 'highThreshold');
            }
            $highThreshold = (int)$highThresholdStr;
        }

        $present = self::getClearValue($opPrefix . 'homeDomain._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'homeDomain._present');
        }
        $homeDomain = null;
        if ($present == 'true') {
            $homeDomainStr = self::getClearValue($opPrefix . 'homeDomain', $map);
            if (!$homeDomainStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'homeDomain');
            }
            $homeDomain = str_replace('"','', $homeDomainStr);
        }

        $present = self::getClearValue($opPrefix . 'signer._present', $map);
        if (!$present) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'signer._present');
        }
        $signer = null;
        $signerWeight = null;
        if ($present == 'true') {
            $signerWeightStr = self::getClearValue($opPrefix . 'signer.weight', $map);
            if (!$signerWeightStr) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'signer.weight');
            }
            if (!is_numeric($signerWeightStr)) {
                throw new InvalidArgumentException('invalid ' . $opPrefix . 'signer.weight');
            }
            $signerWeight = (int)$signerWeightStr;

            $key = self::getClearValue($opPrefix . 'signer.key', $map);
            if (!$key) {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'signer.key');
            }

            if (str_starts_with($key, 'G')) {
                $signer = new XdrSignerKey();
                $signer->setType(new XdrSignerKeyType(XdrSignerKeyType::ED25519));
                $signer->setEd25519(StrKey::decodeAccountId($key));
            } else if (str_starts_with($key, 'X')) {
                $signer = new XdrSignerKey();
                $signer->setType(new XdrSignerKeyType(XdrSignerKeyType::PRE_AUTH_TX));
                $signer->setEd25519(StrKey::decodePreAuth($key));
            } else if (str_starts_with($key, 'T')) {
                $signer = new XdrSignerKey();
                $signer->setType(new XdrSignerKeyType(XdrSignerKeyType::HASH_X));
                $signer->setEd25519(StrKey::decodeSha256Hash($key));
            } else {
                throw new InvalidArgumentException('missing ' . $opPrefix . 'signer.key');
            }
        }

        $builder = new SetOptionsOperationBuilder();
        if ($inflationDest) {
            $builder->setInflationDestination($inflationDest);
        }
        if ($clearFlags) {
            $builder->setClearFlags($clearFlags);
        }
        if ($setFlags) {
            $builder->setSetFlags($setFlags);
        }
        if ($masterWeight) {
            $builder->setMasterKeyWeight($masterWeight);
        }
        if ($lowThreshold) {
            $builder->setLowThreshold($lowThreshold);
        }
        if ($medThreshold) {
            $builder->setMediumThreshold($medThreshold);
        }
        if ($highThreshold) {
            $builder->setHighThreshold($highThreshold);
        }
        if ($homeDomain) {
            $builder->setHomeDomain($homeDomain);
        }
        if ($signer && $signerWeight) {
            $builder->setSigner($signer, $signerWeight);
        }
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }
    private static function getCreatePassiveSellOfferOperation(string $opPrefix, array $map, ?string $sourceAccountId) : CreatePassiveSellOfferOperation
    {
        $sellingStr = self::getClearValue($opPrefix . 'selling', $map);
        if (!$sellingStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'selling');
        }
        $selling = Asset::createFromCanonicalForm($sellingStr);
        if (!$selling) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'selling');
        }
        $buyingStr = self::getClearValue($opPrefix . 'buying', $map);
        if (!$buyingStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'buying');
        }
        $buying = Asset::createFromCanonicalForm($buyingStr);
        if (!$buying) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'buying');
        }

        $amountStr = self::getClearValue($opPrefix.'amount', $map);
        if (!$amountStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'amount');
        }
        if (!is_numeric($amountStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'amount');
        }
        $amount = self::fromAmount($amountStr);

        $priceNStr = self::getClearValue($opPrefix.'price.n', $map);
        if (!$priceNStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'price.n');
        }
        if (!is_numeric($priceNStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'price.n');
        }
        $priceN = (int)$priceNStr;

        $priceDStr = self::getClearValue($opPrefix.'price.d', $map);
        if (!$priceDStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'price.d');
        }
        if (!is_numeric($priceDStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'price.d');
        }
        $priceD = (int)$priceDStr;
        if ($priceD == 0) {
            throw new InvalidArgumentException('price denominator can not be 0 in ' . $opPrefix . 'price.d');
        }
        $price = new Price($priceN, $priceD);

        $builder = new CreatePassiveSellOfferOperationBuilder($selling, $buying,$amount, $price);

        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getManageSellOfferOperation(string $opPrefix, array $map, ?string $sourceAccountId) : ManageSellOfferOperation
    {
        $sellingStr = self::getClearValue($opPrefix . 'selling', $map);
        if (!$sellingStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'selling');
        }
        $selling = Asset::createFromCanonicalForm($sellingStr);
        if (!$selling) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'selling');
        }
        $buyingStr = self::getClearValue($opPrefix . 'buying', $map);
        if (!$buyingStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'buying');
        }
        $buying = Asset::createFromCanonicalForm($buyingStr);
        if (!$buying) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'buying');
        }

        $amountStr = self::getClearValue($opPrefix.'amount', $map);
        if (!$amountStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'amount');
        }
        if (!is_numeric($amountStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'amount');
        }
        $amount = self::fromAmount($amountStr);

        $priceNStr = self::getClearValue($opPrefix.'price.n', $map);
        if (!$priceNStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'price.n');
        }
        if (!is_numeric($priceNStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'price.n');
        }
        $priceN = (int)$priceNStr;

        $priceDStr = self::getClearValue($opPrefix.'price.d', $map);
        if (!$priceDStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'price.d');
        }
        if (!is_numeric($priceDStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'price.d');
        }
        $priceD = (int)$priceDStr;
        if ($priceD == 0) {
            throw new InvalidArgumentException('price denominator can not be 0 in ' . $opPrefix . 'price.d');
        }
        $dec = (float)$priceN / (float)$priceD;
        $offerIdStr = self::getClearValue($opPrefix.'offerID', $map);
        if (!$offerIdStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'offerID');
        }
        if (!is_numeric($offerIdStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'offerID');
        }
        $offerID = (int)$offerIdStr;

        $builder = new ManageSellOfferOperationBuilder($selling, $buying,$amount, strval($dec));

        $builder->setOfferId($offerID);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getManageBuyOfferOperation(string $opPrefix, array $map, ?string $sourceAccountId) : ManageBuyOfferOperation
    {
        $sellingStr = self::getClearValue($opPrefix . 'selling', $map);
        if (!$sellingStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'selling');
        }
        $selling = Asset::createFromCanonicalForm($sellingStr);
        if (!$selling) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'selling');
        }
        $buyingStr = self::getClearValue($opPrefix . 'buying', $map);
        if (!$buyingStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'buying');
        }
        $buying = Asset::createFromCanonicalForm($buyingStr);
        if (!$buying) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'buying');
        }

        $amountStr = self::getClearValue($opPrefix.'buyAmount', $map);
        if (!$amountStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'buyAmount');
        }
        if (!is_numeric($amountStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'buyAmount');
        }
        $amount = self::fromAmount($amountStr);

        $priceNStr = self::getClearValue($opPrefix.'price.n', $map);
        if (!$priceNStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'price.n');
        }
        if (!is_numeric($priceNStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'price.n');
        }
        $priceN = (int)$priceNStr;

        $priceDStr = self::getClearValue($opPrefix.'price.d', $map);
        if (!$priceDStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'price.d');
        }
        if (!is_numeric($priceDStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'price.d');
        }
        $priceD = (int)$priceDStr;
        if ($priceD == 0) {
            throw new InvalidArgumentException('price denominator can not be 0 in ' . $opPrefix . 'price.d');
        }
        $dec = (float)$priceN / (float)$priceD;
        $offerIdStr = self::getClearValue($opPrefix.'offerID', $map);
        if (!$offerIdStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'offerID');
        }
        if (!is_numeric($offerIdStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'offerID');
        }
        $offerID = (int)$offerIdStr;

        $builder = new ManageBuyOfferOperationBuilder($selling, $buying,$amount, strval($dec));

        $builder->setOfferId($offerID);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getPathPaymentStrictSendOperation(string $opPrefix, array $map, ?string $sourceAccountId) : PathPaymentStrictSendOperation
    {
        $sendAssetStr = self::getClearValue($opPrefix . 'sendAsset', $map);
        if (!$sendAssetStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'sendAsset');
        }
        $sendAsset = Asset::createFromCanonicalForm($sendAssetStr);
        if (!$sendAsset) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'sendAsset');
        }
        $sendAmountStr = self::getClearValue($opPrefix.'sendAmount', $map);
        if (!$sendAmountStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'sendAmount');
        }
        if (!is_numeric($sendAmountStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'sendAmount');
        }
        $sendAmount = self::fromAmount($sendAmountStr);

        $destination = self::getClearValue($opPrefix . 'destination', $map);
        if (!$destination) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'destination');
        }
        try {
            KeyPair::fromAccountId($destination);
        } catch (Exception $e) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'destination');
        }

        $destAssetStr = self::getClearValue($opPrefix . 'destAsset', $map);
        if (!$destAssetStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'destAsset');
        }
        $destAsset = Asset::createFromCanonicalForm($destAssetStr);
        if (!$destAsset) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'destAsset');
        }

        $destMinStr= self::getClearValue($opPrefix.'destMin', $map);
        if (!$destMinStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'destMin');
        }
        if (!is_numeric($destMinStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'destMin');
        }
        $destMin = self::fromAmount($destMinStr);

        $path = array();
        $pathLengthKey = $opPrefix.'path.len';
        $pathLengthStr = self::getClearValue($pathLengthKey, $map);
        if ($pathLengthStr) {
            if (!is_numeric($pathLengthStr)) {
                throw new InvalidArgumentException('invalid '.$pathLengthKey);
            }
            $pathLen = (int)$pathLengthStr;
            if($pathLen > 5) {
                throw new InvalidArgumentException('path.len can not be greater than 5 in '.$pathLengthKey.' but is '.strval($pathLen));
            }
            for ($i = 0; $i < $pathLen; $i++) {
                $nextAssetStr = self::getClearValue($opPrefix.'path['.strval($i).']', $map);
                if (!$nextAssetStr) {
                    throw new InvalidArgumentException('missing '.$opPrefix.'path['.strval($i).']');
                }
                $nextAsset = Asset::createFromCanonicalForm($nextAssetStr);
                if (!$nextAsset) {
                    throw new InvalidArgumentException('invalid ' . $opPrefix . 'path['.strval($i).']');
                }
                array_push($path, $nextAsset);
            }
        }
        $builder = new PathPaymentStrictSendOperationBuilder($sendAsset,$sendAmount,$destination,$destAsset,$destMin);
        $builder->setPath($path);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getPathPaymentStrictReceiveOperation(string $opPrefix, array $map, ?string $sourceAccountId) : PathPaymentStrictReceiveOperation
    {
        $sendAssetStr = self::getClearValue($opPrefix . 'sendAsset', $map);
        if (!$sendAssetStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'sendAsset');
        }
        $sendAsset = Asset::createFromCanonicalForm($sendAssetStr);
        if (!$sendAsset) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'sendAsset');
        }
        $sendMaxStr = self::getClearValue($opPrefix.'sendMax', $map);
        if (!$sendMaxStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'sendMax');
        }
        if (!is_numeric($sendMaxStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'sendMax');
        }
        $sendMax = self::fromAmount($sendMaxStr);

        $destination = self::getClearValue($opPrefix . 'destination', $map);
        if (!$destination) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'destination');
        }
        try {
            KeyPair::fromAccountId($destination);
        } catch (Exception $e) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'destination');
        }

        $destAssetStr = self::getClearValue($opPrefix . 'destAsset', $map);
        if (!$destAssetStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'destAsset');
        }
        $destAsset = Asset::createFromCanonicalForm($destAssetStr);
        if (!$destAsset) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'destAsset');
        }

        $destAmountStr = self::getClearValue($opPrefix.'destAmount', $map);
        if (!$destAmountStr) {
            throw new InvalidArgumentException('missing '.$opPrefix.'destAmount');
        }
        if (!is_numeric($destAmountStr)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'destAmount');
        }
        $destAmount = self::fromAmount($destAmountStr);

        $path = array();
        $pathLengthKey = $opPrefix.'path.len';
        $pathLengthStr = self::getClearValue($pathLengthKey, $map);
        if ($pathLengthStr) {
            if (!is_numeric($pathLengthStr)) {
               throw new InvalidArgumentException('invalid '.$pathLengthKey);
           }
           $pathLen = (int)$pathLengthStr;
           if($pathLen > 5) {
               throw new InvalidArgumentException('path.len can not be greater than 5 in '.$pathLengthKey.' but is '.strval($pathLen));
           }
           for ($i = 0; $i < $pathLen; $i++) {
               $nextAssetStr = self::getClearValue($opPrefix.'path['.strval($i).']', $map);
               if (!$nextAssetStr) {
                   throw new InvalidArgumentException('missing '.$opPrefix.'path['.strval($i).']');
               }
               $nextAsset = Asset::createFromCanonicalForm($nextAssetStr);
               if (!$nextAsset) {
                   throw new InvalidArgumentException('invalid ' . $opPrefix . 'path['.strval($i).']');
               }
               array_push($path, $nextAsset);
           }
        }
        $builder = new PathPaymentStrictReceiveOperationBuilder($sendAsset,$sendMax,$destination,$destAsset,$destAmount);
        $builder->setPath($path);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getPaymentOperation(string $opPrefix, array $map, ?string $sourceAccountId) : PaymentOperation
    {
        $destination = self::getClearValue($opPrefix . 'destination', $map);
        if (!$destination) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'destination');
        }
        try {
            KeyPair::fromAccountId($destination);
        } catch (Exception $e) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'destination');
        }
        $assetStr = self::getClearValue($opPrefix . 'asset', $map);
        if (!$assetStr) {
            throw new InvalidArgumentException('missing ' . $opPrefix . 'asset');
        }
        $asset = Asset::createFromCanonicalForm($assetStr);
        if (!$asset) {
            throw new InvalidArgumentException('invalid ' . $opPrefix . 'asset');
        }
        $amountValue = self::getClearValue($opPrefix.'amount', $map);
        if (!$amountValue) {
            throw new InvalidArgumentException('missing '.$opPrefix.'amount');
        }
        if (!is_numeric($amountValue)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'amount');
        }
        $amountValue = self::fromAmount($amountValue);

        $builder = new PaymentOperationBuilder($destination,$asset, $amountValue);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getCreateAccountOperation(string $opPrefix, array $map, ?string $sourceAccountId) : CreateAccountOperation {
        $destination = self::getClearValue($opPrefix.'destination', $map);
        if (!$destination) {
            throw new InvalidArgumentException('missing '.$opPrefix.'destination');
        }
        try {
            KeyPair::fromAccountId($destination);
        } catch (Exception $e) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'destination');
        }
        $startingBalanceValue = self::getClearValue($opPrefix.'startingBalance', $map);
        if (!$startingBalanceValue) {
            throw new InvalidArgumentException('missing '.$opPrefix.'startingBalance');
        }
        if (!is_numeric($startingBalanceValue)) {
            throw new InvalidArgumentException('invalid '.$opPrefix.'startingBalance');
        }
        $startingBalanceValue = self::fromAmount($startingBalanceValue);
        $builder = new CreateAccountOperationBuilder($destination, $startingBalanceValue);
        if ($sourceAccountId != null) {
            $builder->setMuxedSourceAccount(MuxedAccount::fromAccountId($sourceAccountId));
        }
        return $builder->build();
    }

    private static function getClearValue(string $key, array $map) : ?string {
        // check if exists
        if (!array_key_exists($key, $map) || !$map[$key]) {
            return null;
        }
        // remove comment
        $arr = explode('(', $map[$key]);
        return trim($arr[0]);
    }

    private static function getSignatures(string $prefix, ?array $signatures) : array {
        $lines = array();
        if ($signatures) {
            $lines += [$prefix.'signatures.len' => count($signatures)];
            $index = 0;
            foreach($signatures as $signature) {
                if ($signature instanceof XdrDecoratedSignature) {
                    $lines = array_merge($lines, self::getSignatureTx($signature, $index, $prefix));
                }
                $index++;
            }
        } else {
            $lines += [$prefix.'signatures.len' => '0'];
        }
        return $lines;
    }

    private static function getSignatureTx(XdrDecoratedSignature $signature, int $index, string $prefix) : array {
        $lines = array();
        $lines += [$prefix.'signatures['.strval($index).'].hint' => bin2hex($signature->getHint())];
        $lines += [$prefix.'signatures['.strval($index).'].signature' => bin2hex($signature->getSignature())];
        return $lines;
    }

    private static function getOperationTx(AbstractOperation $operation, int $index, string $txPrefix) : array {
        $lines = array();
        $sourceAccount = $operation->getSourceAccount();
        if ($sourceAccount) {
            $lines += [$txPrefix.'operations['.strval($index).'].sourceAccount._present' => 'true'];
            $lines += [$txPrefix.'operations['.strval($index).'].sourceAccount' => $sourceAccount->getAccountId()];
        } else {
            $lines += [$txPrefix.'operations['.strval($index).'].sourceAccount._present' => 'false'];
        }

        $lines += [$txPrefix.'operations['.strval($index).'].body.type' => self::txRepOpTypeUpperCase($operation)];

        $prefix = $txPrefix.'operations['.strval($index).'].body.'.self::txRepOpType($operation).".";

        if ($operation instanceof CreateAccountOperation) {
            $lines += [$prefix.'destination' => $operation->getDestination()];
            $lines += [$prefix.'startingBalance' => self::toAmount($operation->getStartingBalance())];
        } else if ($operation instanceof PaymentOperation) {
            $lines += [$prefix.'destination' => $operation->getDestination()->getAccountId()];
            $lines += [$prefix.'asset' => self::encodeAsset($operation->getAsset())];
            $lines += [$prefix.'amount' => self::toAmount($operation->getAmount())];
        } else if ($operation instanceof PathPaymentStrictReceiveOperation) {
            $lines += [$prefix.'sendAsset' => self::encodeAsset($operation->getSendAsset())];
            $lines += [$prefix.'sendMax' => self::toAmount($operation->getSendMax())];
            $lines += [$prefix.'destination' => $operation->getDestination()->getAccountId()];
            $lines += [$prefix.'destAsset' => self::encodeAsset($operation->getDestAsset())];
            $lines += [$prefix.'destAmount' => self::toAmount($operation->getDestAmount())];
            $path = $operation->getPath();
            if ($path) {
                $lines += [$prefix.'path.len' => count($path)];
                $assetIndex = 0;
                foreach ($path as $asset) {
                    $lines += [$prefix.'path['.strval($assetIndex).']' => self::encodeAsset($asset)];
                    $assetIndex++;
                }
            } else {
                $lines += [$prefix.'path.len' => '0'];
            }
        } else if ($operation instanceof PathPaymentStrictSendOperation) {
            $lines += [$prefix.'sendAsset' => self::encodeAsset($operation->getSendAsset())];
            $lines += [$prefix.'sendAmount' => self::toAmount($operation->getSendAmount())];
            $lines += [$prefix.'destination' => $operation->getDestination()->getAccountId()];
            $lines += [$prefix.'destAsset' => self::encodeAsset($operation->getDestAsset())];
            $lines += [$prefix.'destMin' => self::toAmount($operation->getDestMin())];
            $path = $operation->getPath();
            if ($path) {
                $lines += [$prefix.'path.len' => count($path)];
                $assetIndex = 0;
                foreach ($path as $asset) {
                    $lines += [$prefix.'path['.strval($assetIndex).']' => self::encodeAsset($asset)];
                    $assetIndex++;
                }
            } else {
                $lines += [$prefix.'path.len' => '0'];
            }
        }  else if ($operation instanceof ManageSellOfferOperation) {
            $lines += [$prefix.'selling' => self::encodeAsset($operation->getSelling())];
            $lines += [$prefix.'buying' => self::encodeAsset($operation->getBuying())];
            $lines += [$prefix.'amount' => self::toAmount($operation->getAmount())];
            $price = $operation->getPrice();
            $lines += [$prefix.'price.n' => strval($price->getN())];
            $lines += [$prefix.'price.d' => strval($price->getD())];
            $lines += [$prefix.'offerID' => strval($operation->getOfferId())];
        }  else if ($operation instanceof CreatePassiveSellOfferOperation) {
            $lines += [$prefix.'selling' => self::encodeAsset($operation->getSelling())];
            $lines += [$prefix.'buying' => self::encodeAsset($operation->getBuying())];
            $lines += [$prefix.'amount' => self::toAmount($operation->getAmount())];
            $price = $operation->getPrice();
            $lines += [$prefix.'price.n' => strval($price->getN())];
            $lines += [$prefix.'price.d' => strval($price->getD())];
        }  else if ($operation instanceof SetOptionsOperation) {

            if ($operation->getInflationDestination()) {
                $lines += [$prefix.'inflationDest._present' => 'true'];
                $lines += [$prefix.'inflationDest' => $operation->getInflationDestination()];
            } else {
                $lines += [$prefix.'inflationDest._present' => 'false'];
            }

            if ($operation->getClearFlags()) {
                $lines += [$prefix.'clearFlags._present' => 'true'];
                $lines += [$prefix.'clearFlags' => strval($operation->getClearFlags())];
            } else {
                $lines += [$prefix.'clearFlags._present' => 'false'];
            }

            if ($operation->getSetFlags()) {
                $lines += [$prefix.'setFlags._present' => 'true'];
                $lines += [$prefix.'setFlags' => strval($operation->getSetFlags())];
            } else {
                $lines += [$prefix.'setFlags._present' => 'false'];
            }

            if ($operation->getMasterKeyWeight()) {
                $lines += [$prefix.'masterWeight._present' => 'true'];
                $lines += [$prefix.'masterWeight' => strval($operation->getMasterKeyWeight())];
            } else {
                $lines += [$prefix.'masterWeight._present' => 'false'];
            }

            if ($operation->getLowThreshold()) {
                $lines += [$prefix.'lowThreshold._present' => 'true'];
                $lines += [$prefix.'lowThreshold' => strval($operation->getLowThreshold())];
            } else {
                $lines += [$prefix.'lowThreshold._present' => 'false'];
            }

            if ($operation->getMediumThreshold()) {
                $lines += [$prefix.'medThreshold._present' => 'true'];
                $lines += [$prefix.'medThreshold' => strval($operation->getMediumThreshold())];
            } else {
                $lines += [$prefix.'medThreshold._present' => 'false'];
            }

            if ($operation->getHighThreshold()) {
                $lines += [$prefix.'highThreshold._present' => 'true'];
                $lines += [$prefix.'highThreshold' => strval($operation->getHighThreshold())];
            } else {
                $lines += [$prefix.'highThreshold._present' => 'false'];
            }

            if ($operation->getHomeDomain()) {
                $lines += [$prefix.'homeDomain._present' => 'true'];
                $lines += [$prefix.'homeDomain' => '"'.$operation->getHomeDomain().'"'];
            } else {
                $lines += [$prefix.'homeDomain._present' => 'false'];
            }
            $signer = $operation->getSignerKey();
            if ($signer) {
                $lines += [$prefix.'signer._present' => 'true'];
                if ($signer->getType()->getValue() == XdrSignerKeyType::ED25519) {
                    $lines += [$prefix.'signer.key' => StrKey::encodeAccountId($signer->getEd25519())];
                } else if ($signer->getType()->getValue() == XdrSignerKeyType::PRE_AUTH_TX) {
                    $lines += [$prefix.'signer.key' => StrKey::encodePreAuth($signer->getPreAuthTx())];
                } else if ($signer->getType()->getValue() == XdrSignerKeyType::HASH_X) {
                    $lines += [$prefix.'signer.key' => StrKey::encodeSha256Hash($signer->getHashX())];
                }
                $lines += [$prefix.'signer.weight' => strval($operation->getSignerWeight())];
            } else {
                $lines += [$prefix.'signer._present' => 'false'];
            }
        } else if ($operation instanceof ChangeTrustOperation) {
            $lines += [$prefix.'line' => self::encodeAsset($operation->getAsset())];
            $lines += [$prefix.'limit' => self::toAmount($operation->getLimit())];
        } else if ($operation instanceof AllowTrustOperation) {
            $lines += [$prefix.'trustor' => $operation->getTrustor()];
            $lines += [$prefix.'asset' => $operation->getAssetCode()];
            $auth = $operation->isAuthorize() ? 1 : 0;
            $auth = $operation->isAuthorizeToMaintainLiabilities() ? 2 : $auth;
            $lines += [$prefix.'authorize' => strval($auth)];
        } else if ($operation instanceof AccountMergeOperation) {
            // account merge does not include 'accountMergeOp' prefix
            $lines += [$txPrefix.'operations['.$index.'].body.destination' => $operation->getDestination()->getAccountId()];
        } else if ($operation instanceof ManageDataOperation) {
            $lines += [$prefix.'dataName' => '"'.$operation->getKey().'"'];
            if ($operation->getValue()) {
                $lines += [$prefix.'dataValue._present' => 'true'];
                $lines += [$prefix.'dataValue' => bin2hex($operation->getValue())];
            } else {
                $lines += [$prefix.'dataValue._present' => 'false'];
            }
        } else if ($operation instanceof BumpSequenceOperation) {
            $lines += [$prefix.'bumpTo' => $operation->getBumpTo()->toString()];
        } else if ($operation instanceof ManageBuyOfferOperation) {
            $lines += [$prefix.'selling' => self::encodeAsset($operation->getSelling())];
            $lines += [$prefix.'buying' => self::encodeAsset($operation->getBuying())];
            $lines += [$prefix.'buyAmount' => self::toAmount($operation->getAmount())];
            $price = $operation->getPrice();
            $lines += [$prefix.'price.n' => strval($price->getN())];
            $lines += [$prefix.'price.d' => strval($price->getD())];
            $lines += [$prefix.'offerID' => strval($operation->getOfferId())];
        }

        return $lines;
    }

    private static function encodeAsset(Asset $asset) : string {
        if ($asset instanceof AssetTypeNative) {
            return "XLM";
        } else if ($asset instanceof AssetTypeCreditAlphanum) {
            return $asset->getCode() . ":" . $asset->getIssuer();
        }
        return 'UNKNOWN';
    }

    private static function toAmount(string $value): string {
        $amount = StellarAmount::fromString($value);
        return $amount->getStroopsAsString();
    }

    private static function fromAmount(string $value): string {
        $val = new BigInteger($value);
        $amount = new StellarAmount($val);
        return $amount->getDecimalValueAsString();
    }

    private static function txRepOpTypeUpperCase(AbstractOperation $operation) : string {
        $type = $operation->toXdr()->getBody()->getType()->getValue();

        return match ($type) {
            XdrOperationType::CREATE_ACCOUNT => 'CREATE_ACCOUNT',
            XdrOperationType::PAYMENT => 'PAYMENT',
            XdrOperationType::PATH_PAYMENT_STRICT_RECEIVE => 'PATH_PAYMENT_STRICT_RECEIVE',
            XdrOperationType::MANAGE_SELL_OFFER => 'MANAGE_SELL_OFFER',
            XdrOperationType::CREATE_PASSIVE_SELL_OFFER => 'CREATE_PASSIVE_SELL_OFFER',
            XdrOperationType::SET_OPTIONS => 'SET_OPTIONS',
            XdrOperationType::CHANGE_TRUST => 'CHANGE_TRUST',
            XdrOperationType::ALLOW_TRUST => 'ALLOW_TRUST',
            XdrOperationType::ACCOUNT_MERGE => 'ACCOUNT_MERGE',
            XdrOperationType::INFLATION => 'INFLATION',
            XdrOperationType::MANAGE_DATA => 'MANAGE_DATA',
            XdrOperationType::BUMP_SEQUENCE => 'BUMP_SEQUENCE',
            XdrOperationType::MANAGE_BUY_OFFER => 'MANAGE_BUY_OFFER',
            XdrOperationType::PATH_PAYMENT_STRICT_SEND => 'PATH_PAYMENT_STRICT_SEND',
            XdrOperationType::CREATE_CLAIMABLE_BALANCE => 'CREATE_CLAIMABLE_BALANCE',
            XdrOperationType::CLAIM_CLAIMABLE_BALANCE => 'CLAIM_CLAIMABLE_BALANCE',
            XdrOperationType::BEGIN_SPONSORING_FUTURE_RESERVES => 'BEGIN_SPONSORING_FUTURE_RESERVES',
            XdrOperationType::END_SPONSORING_FUTURE_RESERVES => 'END_SPONSORING_FUTURE_RESERVES',
            XdrOperationType::REVOKE_SPONSORSHIP => 'REVOKE_SPONSORSHIP',
            XdrOperationType::CLAWBACK => 'CLAWBACK',
            XdrOperationType::CLAWBACK_CLAIMABLE_BALANCE => 'CLAWBACK_CLAIMABLE_BALANCE',
            XdrOperationType::SET_TRUST_LINE_FLAGS => 'SET_TRUST_LINE_FLAGS',
            XdrOperationType::LIQUIDITY_POOL_DEPOSIT => 'LIQUIDITY_POOL_DEPOSIT',
            XdrOperationType::LIQUIDITY_POOL_WITHDRAW => 'LIQUIDITY_POOL_WITHDRAW',
            default => strval($type)
        };
    }

    private static function txRepOpType(AbstractOperation $operation) : string {
        $type = $operation->toXdr()->getBody()->getType()->getValue();

        return match ($type) {
            XdrOperationType::CREATE_ACCOUNT => 'createAccountOp',
            XdrOperationType::PAYMENT => 'paymentOp',
            XdrOperationType::PATH_PAYMENT_STRICT_RECEIVE => 'pathPaymentStrictReceiveOp',
            XdrOperationType::MANAGE_SELL_OFFER => 'manageSellOfferOp',
            XdrOperationType::CREATE_PASSIVE_SELL_OFFER => 'createPassiveSellOfferOp',
            XdrOperationType::SET_OPTIONS => 'setOptionsOp',
            XdrOperationType::CHANGE_TRUST => 'changeTrustOp',
            XdrOperationType::ALLOW_TRUST => 'allowTrustOp',
            XdrOperationType::ACCOUNT_MERGE => 'accountMergeOp',
            XdrOperationType::INFLATION => 'inflationOp',
            XdrOperationType::MANAGE_DATA => 'manageDataOp',
            XdrOperationType::BUMP_SEQUENCE => 'bumpSequenceOp',
            XdrOperationType::MANAGE_BUY_OFFER => 'manageBuyOfferOp',
            XdrOperationType::PATH_PAYMENT_STRICT_SEND => 'pathPaymentStrictSendOp',
            XdrOperationType::CREATE_CLAIMABLE_BALANCE => 'createClaimableBalanceOp',
            XdrOperationType::CLAIM_CLAIMABLE_BALANCE => 'claimClaimableBalanceOp',
            XdrOperationType::BEGIN_SPONSORING_FUTURE_RESERVES => 'beginSponsoringFutureReservesOp',
            XdrOperationType::END_SPONSORING_FUTURE_RESERVES => 'endSponsoringFutureReservesOp',
            XdrOperationType::REVOKE_SPONSORSHIP => 'revokeSponsorshipOp',
            XdrOperationType::CLAWBACK => 'clawbackOp',
            XdrOperationType::CLAWBACK_CLAIMABLE_BALANCE => 'clawbackClaimableBalanceOp',
            XdrOperationType::SET_TRUST_LINE_FLAGS => 'setTrustlineFlagsOp',
            XdrOperationType::LIQUIDITY_POOL_DEPOSIT => 'liquidityPoolDepositOp',
            XdrOperationType::LIQUIDITY_POOL_WITHDRAW => 'liquidityPoolWithdrawOp',
            default => strval($type)
        };
    }
}