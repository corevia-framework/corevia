<?php
declare(strict_types=1);
namespace Corevia\Auth;
use Webauthn\{AuthenticatorAssertionResponse,AuthenticatorAssertionResponseValidator,AuthenticatorAttestationResponse,AuthenticatorAttestationResponseValidator,AuthenticatorSelectionCriteria,CredentialRecord,PublicKeyCredential,PublicKeyCredentialCreationOptions,PublicKeyCredentialDescriptor,PublicKeyCredentialParameters,PublicKeyCredentialRequestOptions,PublicKeyCredentialRpEntity,PublicKeyCredentialUserEntity};
use Webauthn\AttestationStatement\{AttestationStatementSupportManager,NoneAttestationStatementSupport};
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/** Optional web-auth/webauthn-lib 5.3.12+ bridge; no subdomain, attestation or conditional-create bypass. */
final readonly class WebAuthnVerifier implements PasskeyVerifier
{
    private object $serializer;
    private AuthenticatorAttestationResponseValidator $registration;
    private AuthenticatorAssertionResponseValidator $assertion;
    private string $host;
    public function __construct(string $origin)
    {
        if(!class_exists(CeremonyStepManagerFactory::class)||!class_exists(CredentialRecord::class)){throw new \LogicException('Install the optional patched web-auth/webauthn-lib ~5.3.12 dependency.');}
        if(!class_exists(\Composer\InstalledVersions::class)||!\Composer\InstalledVersions::isInstalled('web-auth/webauthn-lib')
            ||!preg_match('/^5\.3\.(\d+)\./D',\Composer\InstalledVersions::getVersion('web-auth/webauthn-lib')??'',$version)||(int)$version[1]<12){throw new \LogicException('This bridge supports validated WebAuthn 5.3.12+ within the 5.3 branch; validate other branches before use.');}
        $parts=parse_url($origin);$host=$parts['host']??'';$scheme=$parts['scheme']??'';
        if($parts===false||$host===''||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||isset($parts['path'])||preg_match('/[\x00-\x20\\\\]/',$origin)
            ||$scheme!=='https'&&!(getenv('APP_ENV')!=='production'&&$scheme==='http'&&in_array($host,['localhost','127.0.0.1'],true))){throw new \InvalidArgumentException('Passkeys require an exact HTTPS origin; HTTP loopback is development-only.');}
        $this->host=$host;$support=new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);$this->serializer=(new WebauthnSerializerFactory($support))->create();
        $factory=new CeremonyStepManagerFactory();$factory->setAllowedOrigins([$origin],false);$factory->setAttestationStatementSupportManager($support);
        $this->registration=new AuthenticatorAttestationResponseValidator($factory->creationCeremony());$this->assertion=new AuthenticatorAssertionResponseValidator($factory->requestCeremony());
    }
    private function array(object $value):array{return json_decode($this->serializer->serialize($value,'json'),true,32,JSON_THROW_ON_ERROR);}
    private function load(array $data,string $class):object
    {
        $wire=json_encode($data,JSON_THROW_ON_ERROR,32);if(strlen($wire)>65536){throw new \LengthException('WebAuthn object exceeds 64 KiB.');}return $this->serializer->deserialize($wire,$class,'json');
    }
    public function registrationOptions(array $user,string $challenge,array $excluded):array
    {
        if(strlen($challenge)!==32||!preg_match('/^[a-f0-9]{32}$/D',$user['id']??'')||count($excluded)>10){throw new \InvalidArgumentException('Invalid registration challenge or account.');}
        $options=new PublicKeyCredentialCreationOptions(new PublicKeyCredentialRpEntity('', $this->host),new PublicKeyCredentialUserEntity($user['email'],hex2bin($user['id']),$user['name']),$challenge,
            [new PublicKeyCredentialParameters('public-key',-7),new PublicKeyCredentialParameters('public-key',-257)],new AuthenticatorSelectionCriteria(userVerification:'required',residentKey:'required'),'none',
            array_map(fn(array $source)=>$this->load($source,CredentialRecord::class)->getPublicKeyCredentialDescriptor(),$excluded),60000);
        return $this->array($options);
    }
    public function requestOptions(string $challenge):array
    {
        if(strlen($challenge)!==32){throw new \InvalidArgumentException('Invalid assertion challenge.');}return $this->array(new PublicKeyCredentialRequestOptions($challenge,$this->host,[],userVerification:'required',timeout:60000));
    }
    public function register(array $credential,array $options):array
    {
        $key=$this->load($credential,PublicKeyCredential::class);$options=$this->load($options,PublicKeyCredentialCreationOptions::class);
        if(!$key->response instanceof AuthenticatorAttestationResponse||$options->authenticatorSelection?->userVerification!=='required'||$options->mediation!==null){throw new \RuntimeException('Use a standard user-verified registration ceremony.');}
        $source=$this->registration->check($key->response,$options,$this->host);if(!hash_equals($key->rawId,$source->publicKeyCredentialId)){throw new \RuntimeException('Credential ID mismatch.');}return $this->array($source);
    }
    public function verify(array $credential,array $options,array $source):array
    {
        $key=$this->load($credential,PublicKeyCredential::class);$options=$this->load($options,PublicKeyCredentialRequestOptions::class);$source=$this->load($source,CredentialRecord::class);
        if(!$key->response instanceof AuthenticatorAssertionResponse||$options->userVerification!=='required'||!hash_equals($key->rawId,$source->publicKeyCredentialId)){throw new \RuntimeException('Invalid user-verified assertion.');}
        return $this->array($this->assertion->check($source,$key->response,$options,$this->host,$source->userHandle));
    }
}
