import {
  Card,
  CardBody,
  CardTitle,
  Container,
  Eyebrow,
  GRID_2,
  Section,
  SectionTitle,
  StepNumber,
} from "@/components/marketing/primitives";

const PORTALS: { title: string; steps: { title: string; body: string }[] }[] = [
  {
    title: "Doctor portal",
    steps: [
      {
        title: "Effortless patient management",
        body: "Easily enroll, track and communicate with patients through an all-in-one dashboard, saving you time and ensuring seamless supervision.",
      },
      {
        title: "HIPAA-compliant messaging and video calls",
        body: "Securely connect with patients through built-in messaging and video conferencing, keeping all communication documented and accessible.",
      },
      {
        title: "Automated tracking and engagement",
        body: "Set up daily automated messages, monitor progress in real time, and provide guidance with minimal effort, all while enhancing patient outcomes.",
      },
    ],
  },
  {
    title: "Patient portal",
    steps: [
      {
        title: "Video conferencing",
        body: "HIPAA-compliant video conferencing to connect doctor and patient face to face.",
      },
      {
        title: "Patient tracking",
        body: "A secure hub for tracking patient progress and clinic data, all in one place.",
      },
      {
        title: "Increase credibility and grow your practice",
        body: "George Dan lost 33 lbs and 29 inches in 42 days!",
      },
    ],
  },
];

/** `.st` — one row of a "how it works" list. */
function Step({ index, title, body }: { index: number; title: string; body: string }) {
  return (
    <div className="flex gap-4 border-t border-border py-[18px]">
      <StepNumber>{index}</StepNumber>
      <div>
        <b className="block text-[17px]">{title}</b>
        <span className="text-[15px] text-muted-foreground">{body}</span>
      </div>
    </div>
  );
}

const NOTES = [
  {
    title: "Stay on track, every step of the way",
    body: "ChiroThin Tracker makes it easy to stay on track with your weight loss goals. Log your daily progress, track your results, and receive automated guidance, all in one place. Stay connected with your doctor through secure messaging and video calls. Access personalized meal plans, training videos and resources tailored to your journey. With ChiroThin Tracker, you’re never alone in your transformation!",
  },
  {
    title: "The platform does the heavy lifting",
    body: "The training and resources tabs are loaded with everything your patients need to complete the ChiroThin® doctor-supervised weight loss program. Upload your own customized resources and training videos to personalize your tracker. It takes the program to the next level with daily doctor supervision, a new standard that lets you charge more for your programs while providing better care in less time.",
  },
];

export function HowItWorks() {
  return (
    <Section>
      <Container>
        <Eyebrow>How it works</Eyebrow>
        <SectionTitle>
          Streamlined workflow for doctors, guided success for patients
        </SectionTitle>

        <div className={`mt-12 grid gap-5 ${GRID_2}`}>
          {PORTALS.map((portal) => (
            <Card key={portal.title} className="p-9">
              <h3 className="font-serif text-[28px] leading-[1.1] font-medium tracking-[-0.01em]">
                {portal.title}
              </h3>
              <div className="mt-[18px]">
                {portal.steps.map((step, index) => (
                  <Step key={step.title} index={index + 1} {...step} />
                ))}
              </div>
            </Card>
          ))}
        </div>

        <div className={`mt-5 grid gap-5 ${GRID_2}`}>
          {NOTES.map((note) => (
            <div key={note.title} className="px-1 py-2">
              <CardTitle>{note.title}</CardTitle>
              <CardBody>{note.body}</CardBody>
            </div>
          ))}
        </div>
      </Container>
    </Section>
  );
}