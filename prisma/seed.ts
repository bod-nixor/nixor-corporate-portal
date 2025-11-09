import { PrismaClient } from "@prisma/client";

const prisma = new PrismaClient();

async function main() {
  await prisma.auditLog.deleteMany();
  await prisma.emailLog.deleteMany();
  await prisma.payment.deleteMany();
  await prisma.consentForm.deleteMany();
  await prisma.hRNote.deleteMany();
  await prisma.participation.deleteMany();
  await prisma.registration.deleteMany();
  await prisma.endeavourTag.deleteMany();
  await prisma.endeavour.deleteMany();
  await prisma.entityMembership.deleteMany();
  await prisma.tag.deleteMany();
  await prisma.parentContact.deleteMany();
  await prisma.entity.deleteMany();
  await prisma.user.deleteMany();

  const [, hr, managerA, managerB, ...volunteers] = await Promise.all([
    prisma.user.create({
      data: {
        email: "admin@nixorcollege.edu.pk",
        name: "Admin",
        role: "ADMIN",
        googleId: "admin-google"
      }
    }),
    prisma.user.create({
      data: {
        email: "hr@nixorcollege.edu.pk",
        name: "HR",
        role: "HR",
        googleId: "hr-google"
      }
    }),
    prisma.user.create({
      data: {
        email: "manager-a@nixorcollege.edu.pk",
        name: "Manager A",
        role: "ENTITY_MANAGER",
        googleId: "manager-a"
      }
    }),
    prisma.user.create({
      data: {
        email: "manager-b@nixorcollege.edu.pk",
        name: "Manager B",
        role: "ENTITY_MANAGER",
        googleId: "manager-b"
      }
    }),
    prisma.user.create({
      data: {
        email: "vol1@nixorcollege.edu.pk",
        name: "Volunteer One",
        role: "VOLUNTEER",
        studentId: "S001",
        googleId: "vol1"
      }
    }),
    prisma.user.create({
      data: {
        email: "vol2@nixorcollege.edu.pk",
        name: "Volunteer Two",
        role: "VOLUNTEER",
        studentId: "S002",
        googleId: "vol2"
      }
    }),
    prisma.user.create({
      data: {
        email: "vol3@nixorcollege.edu.pk",
        name: "Volunteer Three",
        role: "VOLUNTEER",
        studentId: "S003",
        googleId: "vol3"
      }
    }),
    prisma.user.create({
      data: {
        email: "vol4@nixorcollege.edu.pk",
        name: "Volunteer Four",
        role: "VOLUNTEER",
        studentId: "S004",
        googleId: "vol4"
      }
    }),
    prisma.user.create({
      data: {
        email: "vol5@nixorcollege.edu.pk",
        name: "Volunteer Five",
        role: "VOLUNTEER",
        studentId: "S005",
        googleId: "vol5"
      }
    }),
    prisma.user.create({
      data: {
        email: "vol6@nixorcollege.edu.pk",
        name: "Volunteer Six",
        role: "VOLUNTEER",
        studentId: "S006",
        googleId: "vol6"
      }
    })
  ]);

  const entityA = await prisma.entity.create({
    data: {
      name: "Nixor Community Service",
      slug: "community-service",
      publishQuotaPer7d: 3
    }
  });

  const entityB = await prisma.entity.create({
    data: {
      name: "Nixor Environment Club",
      slug: "environment-club",
      publishQuotaPer7d: 2
    }
  });

  await prisma.entityMembership.createMany({
    data: [
      { userId: managerA.id, entityId: entityA.id, role: "ENTITY_MANAGER" },
      { userId: managerB.id, entityId: entityB.id, role: "ENTITY_MANAGER" },
      ...volunteers.slice(0, 3).map((vol) => ({ userId: vol.id, entityId: entityA.id, role: "VOLUNTEER" })),
      ...volunteers.slice(3).map((vol) => ({ userId: vol.id, entityId: entityB.id, role: "VOLUNTEER" }))
    ]
  });

  const tags = await Promise.all([
    prisma.tag.create({ data: { name: "Food", slug: "food" } }),
    prisma.tag.create({ data: { name: "Education", slug: "education" } }),
    prisma.tag.create({ data: { name: "Environment", slug: "environment" } })
  ]);

  const endeavours = await Promise.all([
    prisma.endeavour.create({
      data: {
        entityId: entityA.id,
        title: "Community Kitchen",
        description: "Prepare meals for the underserved community.",
        venue: "Main Hall",
        startAt: new Date(Date.now() + 86400000),
        endAt: new Date(Date.now() + 90000000),
        requiresTransportPayment: false,
        createdByUserId: managerA.id
      }
    }),
    prisma.endeavour.create({
      data: {
        entityId: entityA.id,
        title: "Library Drive",
        description: "Organize donated books and catalog them.",
        venue: "Library",
        startAt: new Date(Date.now() + 172800000),
        endAt: new Date(Date.now() + 180000000),
        requiresTransportPayment: false,
        createdByUserId: managerA.id
      }
    }),
    prisma.endeavour.create({
      data: {
        entityId: entityB.id,
        title: "Beach Cleanup",
        description: "Join the cleanup crew to protect marine life.",
        venue: "Seaview Beach",
        startAt: new Date(Date.now() + 259200000),
        endAt: new Date(Date.now() + 266400000),
        requiresTransportPayment: true,
        createdByUserId: managerB.id
      }
    }),
    prisma.endeavour.create({
      data: {
        entityId: entityB.id,
        title: "Tree Plantation",
        description: "Plant trees in the city park.",
        venue: "City Park",
        startAt: new Date(Date.now() + 345600000),
        endAt: new Date(Date.now() + 352800000),
        requiresTransportPayment: false,
        createdByUserId: managerB.id
      }
    }),
    prisma.endeavour.create({
      data: {
        entityId: entityA.id,
        title: "Tutoring Session",
        description: "Support juniors in math and science.",
        venue: "Room 203",
        startAt: new Date(Date.now() + 432000000),
        endAt: new Date(Date.now() + 438000000),
        requiresTransportPayment: false,
        createdByUserId: managerA.id
      }
    })
  ]);

  await prisma.endeavourTag.createMany({
    data: [
      { endeavourId: endeavours[0].id, tagId: tags[0].id },
      { endeavourId: endeavours[1].id, tagId: tags[1].id },
      { endeavourId: endeavours[2].id, tagId: tags[2].id }
    ]
  });

  await Promise.all(
    volunteers.map((volunteer, index) =>
      prisma.parentContact.create({
        data: {
          volunteerId: volunteer.id,
          name: `Parent ${index + 1}`,
          relationship: "Parent",
          email: `parent${index + 1}@example.com`
        }
      })
    )
  );

  const registration = await prisma.registration.create({
    data: {
      endeavourId: endeavours[0].id,
      volunteerId: volunteers[0].id,
      status: "REGISTERED"
    }
  });

  await prisma.participation.createMany({
    data: [
      {
        volunteerId: volunteers[0].id,
        entityId: entityA.id,
        endeavourId: endeavours[0].id,
        participatedAt: new Date(Date.now() - 86400000)
      },
      {
        volunteerId: volunteers[1].id,
        entityId: entityA.id,
        participatedAt: new Date(Date.now() - 604800000)
      }
    ]
  });

  await prisma.hRNote.create({
    data: {
      volunteerId: volunteers[0].id,
      entityId: entityA.id,
      authorId: hr.id,
      note: "Punctual and reliable."
    }
  });

  await prisma.consentForm.create({
    data: {
      registrationId: registration.id,
      formSnapshotJson: { signedBy: "Parent 1" },
      submittedAt: new Date()
    }
  });

  await prisma.payment.create({
    data: {
      registrationId: registration.id,
      amountCents: 1500,
      currency: "PKR",
      status: "PAID"
    }
  });

  console.log("Seeded data successfully");
}

main()
  .catch((error) => {
    console.error(error);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
