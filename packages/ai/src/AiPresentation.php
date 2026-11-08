<?php
declare(strict_types=1);
namespace Corevia\Ai;
use Corevia\Http\Request;

/** Display preferences only; provider settings and conversations never enter this catalog. */
final readonly class AiPresentation
{
    public function __construct(public string $locale='en') { if (!in_array($locale,['en','ur','ar'],true)) { throw new \InvalidArgumentException('Unsupported AI starter locale.'); } }
    public static function for(Request $request):self { $locale=$request->cookie('corevia_locale');return new self(in_array($locale,['en','ur','ar'],true)?$locale:'en'); }
    public function messages():array
    {
        $en=['AI workspace','Dashboard','A little room for big ideas.','Ask a question, explore an idea, or work through a draft.','Your message','Write your message here…','Messages are sent to the model configured by this application. Avoid sharing secrets.','Send','Cancel','Clear','Response','Your next idea starts here.','Send a message to start. Responses appear here as they arrive.','Ready when you are.','Generating…','Complete.','Cancelled.','Generation unavailable. Please try again.','AI is not configured for this application yet.','Partial response','Streaming','Finished','Stopped','This page keeps no conversation history after you leave.'];
        $ur=['اے آئی ورک اسپیس','ڈیش بورڈ','بڑے خیالات کے لیے ایک جگہ۔','سوال پوچھیں، خیال دریافت کریں، یا مسودہ بہتر بنائیں۔','آپ کا پیغام','اپنا پیغام یہاں لکھیں…','پیغامات اس ایپ کے منتخب کردہ ماڈل کو بھیجے جاتے ہیں۔ راز شیئر نہ کریں۔','بھیجیں','منسوخ کریں','صاف کریں','جواب','آپ کا اگلا خیال یہاں سے شروع ہوتا ہے۔','شروع کرنے کے لیے پیغام بھیجیں۔ جواب آتے ہی یہاں دکھائی دے گا۔','آپ کے لیے تیار۔','جواب تیار ہو رہا ہے…','مکمل۔','منسوخ۔','جواب دستیاب نہیں۔ دوبارہ کوشش کریں۔','اس ایپ کے لیے اے آئی ابھی ترتیب نہیں دیا گیا۔','نامکمل جواب','جاری ہے','مکمل','روک دیا گیا','یہ صفحہ بند کرنے کے بعد گفتگو کی تاریخ محفوظ نہیں رہتی۔'];
        $ar=['مساحة الذكاء الاصطناعي','لوحة التحكم','مساحة صغيرة لأفكار كبيرة.','اطرح سؤالاً أو استكشف فكرة أو حسّن مسودة.','رسالتك','اكتب رسالتك هنا…','تُرسل الرسائل إلى النموذج الذي أعدّه هذا التطبيق. تجنّب مشاركة الأسرار.','إرسال','إلغاء','مسح','الرد','فكرتك التالية تبدأ هنا.','أرسل رسالة للبدء. ستظهر أجزاء الرد فور وصولها.','جاهز عندما تكون مستعداً.','جارٍ إنشاء الرد…','اكتمل.','أُلغي.','الرد غير متاح. حاول مرة أخرى.','لم يُعدّ الذكاء الاصطناعي لهذا التطبيق بعد.','رد جزئي','جارٍ البث','مكتمل','متوقف','لا تحتفظ هذه الصفحة بسجل المحادثة بعد مغادرتها.'];
        $en[]='Create a message';$ur[]='پیغام لکھیں';$ar[]='إنشاء رسالة';
        return array_combine($en,match($this->locale) {'ur'=>$ur,'ar'=>$ar,default=>$en});
    }
    public function text(string $key):string { return $this->messages()[$key]??$key; }
}
